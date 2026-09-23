<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\User;
use App\Models\Creem;
use App\Models\Price;
use RuntimeException;
use App\Models\Subscription;
use Hypervel\Support\Facades\Log;

/**
 * Creem 這條金流的業務邏輯。
 *
 * **與 Paddle / Stripe 的三個結構差異**，整份實作都是繞著它們轉的：
 *
 * 1. **轉址型結帳**。Creem 沒有 overlay SDK，`POST /checkouts` 回一個 `checkout_url`，
 *    前端要把使用者送過去，付完再帶回 `success_url`。形狀上跟 Stripe 那條路一樣，
 *    跟 Paddle 的 overlay 完全不同。
 * 2. **價格綁在 product 上**。Creem 沒有 product → 多 price 的階層，所以我們的
 *    「Pro 月繳」「Pro 年繳」在 Creem 是兩個獨立 product，對應表掛在 `Price` 上。
 * 3. **試用期也綁在 product 上，且結帳時不可覆寫**，而且 Creem 沒有 Paddle 那種
 *    activate 端點可以當場結束試用。「首月免費只送第一次」因此只能靠**同一個 price
 *    建兩個 product**（含試用 / 不含）來守，結帳時依資格挑一個——見 `productIdFor()`。
 */
class CreemSubscriptionService
{
    /**
     * 建立結帳。
     *
     * 回傳形狀刻意與 Paddle / Stripe 那兩條路平行（都是 `[供應商名 => [...]]`），
     * 讓前端用同一個 `paymentMethod` 參數分派，不必為 Creem 另開一條資料通道。
     *
     * @return array<string, mixed>
     */
    public function createCheckout(User $user, Plan $plan, Price $price, Subscription $subscription): array
    {
        $successUrl = (string) env('CREEM_SUCCESS_URL', '');

        if ($successUrl === '') {
            throw new RuntimeException('CREEM_SUCCESS_URL is not configured.');
        }

        $withTrial = (new SubscriptionService())->isEligibleForFreeMonth(
            (string) $subscription->user_id,
            (string) $subscription->getKey()
        );

        $payload = [
            'product_id'  => $this->productIdFor($price, $withTrial),
            'success_url' => $successUrl,
            // Creem 會把 metadata 原封帶進後續每一則 subscription.* webhook，
            // 這是我們把它的訂閱對回自己這筆的主要線索。
            'metadata' => ['subscriptionId' => (string) $subscription->getKey()],
            // request_id 會在 success_url 與 webhook 裡回傳，當作冪等鍵用。
            'request_id' => (string) $subscription->getKey(),
            'customer'   => ['email' => $user->email],
        ];

        $checkout = (new CreemClient())->createCheckout($payload);

        $checkoutUrl = $checkout['checkout_url'] ?? null;

        if (!is_string($checkoutUrl) || $checkoutUrl === '') {
            throw new RuntimeException('Creem did not return a checkout_url.');
        }

        $subscription->creem()->create([
            'foreign_type' => Subscription::class,
            'creem_id'     => (string) ($checkout['id'] ?? ''),
            'creem_detail' => $checkout,
            'variant'      => $withTrial ? Creem::VARIANT_TRIAL : Creem::VARIANT_STANDARD,
        ]);

        return [
            'creem' => [
                'checkout_url' => $checkoutUrl,
                'checkout_id'  => (string) ($checkout['id'] ?? ''),
                // 前端據此決定要不要顯示「測試模式」提示，也方便排查是不是連錯環境。
                'test_mode' => (new CreemClient())->isTestMode(),
            ],
            'customData' => ['subscriptionId' => (string) $subscription->getKey()],
        ];
    }

    /**
     * 這個 price 該用哪一個 Creem product。
     *
     * 還有首月免費資格 → 用含 `trial_period_days` 的那一個；沒有 → 用不含試用的。
     * 兩個 product 都由 `creem:sync` 事先建好（見 App\Console\Commands\Creem\Sync）。
     *
     * 找不到對應的 product 一律拋例外，**不要默默退回另一個變體**：讓沒資格的人
     * 拿到含試用的 product，就是把「訂閱 → 取消 → 再訂閱」的無限續杯洞打開；
     * 反過來讓有資格的人拿到不含試用的，則是當場多收他一個月的錢。兩種都寧可
     * 結帳失敗也不要靜靜發生。
     */
    public function productIdFor(Price $price, bool $withTrial): string
    {
        $variant = $withTrial ? Creem::VARIANT_TRIAL : Creem::VARIANT_STANDARD;

        $creem = $price->creem()->where('variant', $variant)->first();

        if (!$creem || !$creem->creem_id) {
            throw new RuntimeException(sprintf(
                'Price %s has no Creem product for the "%s" variant. Run `php artisan creem:sync`.',
                (string) $price->getKey(),
                $variant
            ));
        }

        return (string) $creem->creem_id;
    }

    /**
     * Creem 的訂閱狀態 → 我們自己的 `status`。
     *
     * 判斷與 `PaddleSubscriptionService::statusFor()` 同一套哲學，理由也一樣——
     * `Subscription::scopeActive()` 只認 `active` 與未到期的 `trial`，寫錯的下場是
     * 權限問題而不是顯示問題：
     *
     * - `trialing` → `trial`
     * - `active` → `active`
     * - `past_due` → **`active`**。Creem 這時還在重試（後面才會送 `unpaid`），
     *   這段期間停權等於卡片過期就讓付費客人斷線
     * - `unpaid`（重試用盡）／`expired`／`canceled`／`paused` → `canceled`
     * - 未知狀態 → `canceled`（fail closed）。Creem 日後新增狀態時，寧可少給權限
     *   也不要因為 default 落在 active 而把不該有權限的人放進來
     */
    public function statusFor(string $creemStatus): string
    {
        return match ($creemStatus) {
            'trialing' => Subscription::STATUS_TRIAL,
            // scheduled_cancel：使用者已按取消、但這一期已付款，權限要保留到期末。
            // 少了這一行它會落到 default 變成 canceled，付費使用者一按取消就當場斷線。
            'active', 'past_due', 'scheduled_cancel' => Subscription::STATUS_ACTIVE,
            default => Subscription::STATUS_CANCELED,
        };
    }

    /**
     * 照 Creem 回報的狀態與日期寫回我們自己的訂閱。
     *
     * @param array<string, mixed> $creemSubscription
     */
    public function syncFromCreem(Subscription $subscription, array $creemSubscription): void
    {
        $attributes = [
            'status' => $this->statusFor((string) ($creemSubscription['status'] ?? '')),
        ];

        if ($createdAt = $creemSubscription['created_at'] ?? null) {
            $attributes['start_date'] = Carbon::parse((string) $createdAt)->toDateTime();
        }

        // 取消或暫停後 current_period_end_date 可能是 null，這時保留原本的日期——
        // 寫成 null 會讓 scopeActive() 把早該結束的訂閱當成永遠有效。
        if ($periodEnd = $creemSubscription['current_period_end_date'] ?? null) {
            $attributes['next_date'] = Carbon::parse((string) $periodEnd)->toDateTime();
        }

        if ($canceledAt = $creemSubscription['canceled_at'] ?? null) {
            $attributes['cancellation_date'] = Carbon::parse((string) $canceledAt)->toDateTime();
        }

        $subscription->fill($attributes)->save();
    }

    public function cancel(Subscription $subscription): void
    {
        $creemSubscriptionId = $this->resolveCreemSubscriptionId($subscription);

        if ($creemSubscriptionId === null) {
            throw new RuntimeException(sprintf(
                'Subscription %s has no Creem subscription to cancel.',
                (string) $subscription->getKey()
            ));
        }

        (new CreemClient())->cancelSubscription($creemSubscriptionId);
    }

    /**
     * 找出這筆訂閱在 Creem 上真正的訂閱 id（sub_…）。
     *
     * `creems` 對應列在結帳當下存的是 **checkout** 的 id（ch_…）——那時 Creem 還沒建
     * 訂閱——要等 subscription.* webhook 進來才會改寫成 sub_…。webhook 延遲、失敗，
     * 或本機開發收不到時，手上就只有 checkout id，拿它去打取消只會失敗。
     *
     * 所以遇到 checkout id 時回頭問 Creem 這個 checkout 建出了哪個訂閱，並把結果
     * 寫回對應列，下次就不必再查。查不到（例如使用者其實沒付完款）回 null，
     * 由呼叫端決定怎麼回應。
     */
    public function resolveCreemSubscriptionId(Subscription $subscription): ?string
    {
        $creem = $subscription->creem()->whereNotNull('creem_id')->first();

        if (!$creem) {
            return null;
        }

        $storedId = (string) $creem->creem_id;

        if (!self::isCheckoutId($storedId)) {
            return $storedId;
        }

        $checkout = (new CreemClient())->getCheckout($storedId);
        $subscriptionId = self::subscriptionIdFromCheckout($checkout);

        if ($subscriptionId === null) {
            return null;
        }

        $creem->fill(['creem_id' => $subscriptionId])->save();

        return $subscriptionId;
    }

    /** Creem 的 checkout id 一律以 ch_ 開頭；訂閱 id 是 sub_。 */
    public static function isCheckoutId(string $creemId): bool
    {
        return str_starts_with($creemId, 'ch_');
    }

    /**
     * 從 checkout 物件取出它建立的訂閱 id。
     *
     * Creem 的 checkout 會把訂閱嵌成物件（`subscription.id`），但展開與否可能因
     * 端點而異，也可能只給字串 id，兩種都接。還沒建訂閱（沒付完款）時為 null。
     *
     * @param array<string, mixed> $checkout
     */
    public static function subscriptionIdFromCheckout(array $checkout): ?string
    {
        $subscription = $checkout['subscription'] ?? null;

        $id = is_array($subscription) ? ($subscription['id'] ?? null) : $subscription;

        return is_string($id) && str_starts_with($id, 'sub_') ? $id : null;
    }

    /**
     * 從 webhook 內容找出這是我們哪一筆訂閱。
     *
     * 兩條路，順序有意義：
     *
     * 1. `metadata.subscriptionId`——結帳時塞進去的，Creem 會原封帶回每一則事件，
     *    是最直接的對應
     * 2. `creems` 表以 `creem_id` 反查，給 metadata 因故缺漏時兜底
     *
     * @param array<string, mixed> $object
     */
    public function resolveSubscription(array $object): ?Subscription
    {
        $metadata = $object['metadata'] ?? null;
        $id = is_array($metadata) ? ($metadata['subscriptionId'] ?? null) : null;

        if (is_string($id) && $id !== '' && $subscription = Subscription::query()->find($id)) {
            return $subscription;
        }

        $creemId = $object['id'] ?? null;

        if (!is_string($creemId) || $creemId === '') {
            return null;
        }

        $creem = Creem::query()
            ->where('foreign_type', Subscription::class)
            ->where('creem_id', $creemId)
            ->first();

        return $creem ? Subscription::query()->find($creem->foreign_id) : null;
    }

    /**
     * 所有 `subscription.*` 事件共用的處理。
     *
     * 與 Paddle 那條路的取捨不同：**這裡直接採信 webhook 帶來的 object**，不回頭
     * 再查一次 API。理由是 Creem 的驗簽涵蓋整個 raw body，內容沒有被竄改的可能，
     * 而它的訂閱物件本身就帶著我們要的全部欄位（status、期間、canceled_at）。
     *
     * 代價是亂序事件可能互相覆蓋。緩解方式是 `updated_at` 比對：比我們手上這份舊的
     * 事件直接丟掉，晚到的 `subscription.update` 就蓋不掉已經寫好的 `canceled`。
     *
     * @param array<string, mixed> $object
     */
    public function handleSubscriptionEvent(Subscription $subscription, array $object): void
    {
        if ($this->isStale($subscription, $object)) {
            Log::info('Skipped a stale Creem subscription event', [
                'subscription_id' => (string) $subscription->getKey(),
                'creem_id'        => (string) ($object['id'] ?? ''),
            ]);

            return;
        }

        $this->syncFromCreem($subscription, $object);
        $this->rememberCreemSubscription($subscription, $object);
    }

    /**
     * 這則事件是不是比我們已經寫進去的還舊。
     *
     * Creem 不保證送達順序，而我們採信 payload（見 handleSubscriptionEvent），
     * 所以要自己擋。沒有 `updated_at` 可比時一律當成新的處理——寧可多寫一次
     * （冪等）也不要漏掉狀態變化。
     *
     * @param array<string, mixed> $object
     */
    private function isStale(Subscription $subscription, array $object): bool
    {
        $incoming = $object['updated_at'] ?? null;

        if (!is_string($incoming) || $incoming === '') {
            return false;
        }

        $creem = $subscription->creem()->whereNotNull('creem_id')->first();
        $stored = is_array($creem?->creem_detail) ? ($creem->creem_detail['updated_at'] ?? null) : null;

        if (!is_string($stored) || $stored === '') {
            return false;
        }

        return Carbon::parse($incoming)->lessThan(Carbon::parse($stored));
    }

    /**
     * 記住（或更新）這筆訂閱對應的 Creem 訂閱列。
     *
     * 已經有同一個 `creem_id` 就只刷新 detail，不要再插一列——`subscription.*`
     * 會反覆進來，每次 create 會讓這張表長滿重複資料。
     *
     * 首購時這一列原本存的是 **checkout** 的 id（結帳當下還沒有 subscription），
     * 所以第一則訂閱事件進來時會把它改寫成 subscription 的 id。
     *
     * @param array<string, mixed> $object
     */
    private function rememberCreemSubscription(Subscription $subscription, array $object): void
    {
        $creemId = (string) ($object['id'] ?? '');

        if ($creemId === '') {
            return;
        }

        $existing = $subscription->creem()->where('creem_id', $creemId)->first()
            ?? $subscription->creem()->first();

        if ($existing) {
            $existing->fill(['creem_id' => $creemId, 'creem_detail' => $object])->save();

            return;
        }

        $subscription->creem()->create([
            'foreign_type' => Subscription::class,
            'creem_id'     => $creemId,
            'creem_detail' => $object,
        ]);
    }
}
