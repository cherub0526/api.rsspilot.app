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
 *    activate 端點可以當場結束試用。「試用只送第一次」因此只能靠**同一個 price
 *    建兩個 product**（含試用 / 不含）來守，結帳時依資格挑一個——見 `productIdFor()`。
 *
 * **新客優惠是免費試用 14 天**（`Subscription::TRIAL_DAYS`，2026-10 RSS-3 由
 * owner 定案），**月繳與年繳都適用**，走含 `trial_period_days` 的 product。
 *
 * 另有一條**選用**的年繳首年折價路：`prices.first_period_price` 填了值時，那個
 * price 改走 standard product + 一張 `duration: once` 的折扣碼
 * （`introDiscountCodeFor()`）。product 的價格建了就不能改（見 Creem\Sync），
 * 所以「首年便宜、之後原價」只有折扣碼這條路。**目前每一筆 price 的
 * `first_period_price` 都是 null**（owner 決定兩個方案都不降價），所以這條路
 * 現在不會被走到；留著是因為它不需要任何決定就能運作，而機制本身是對的。
 *
 * **試用與折價互斥**，而且是結構性的（見 `grantsTrial()`）——同一筆結帳不會
 * 同時掛試用期與折扣碼。兩者也**共用同一個終生一次的資格閘門**
 * （`SubscriptionService::isEligibleForIntroOffer()`），不然「用掉試用 → 取消 →
 * 改訂年繳拿折價」就能一個人吃兩份。
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

        $eligible = (new SubscriptionService())->isEligibleForIntroOffer(
            (string) $subscription->user_id,
            (string) $subscription->getKey()
        );

        // 新客優惠有兩種形式，**同一筆結帳只會拿到其中一種**：
        //
        // - 預設（含現在的全部 price）→ 免費試用 14 天，走含 trial_period_days
        //   的 product，月繳與年繳都一樣
        // - 該 price 設了 `first_period_price` → 改走首期折價（standard product
        //   + 一張 duration=once 的折扣碼），不再送試用
        //
        // 互斥是結構性的而不是靠這兩行的順序：`grantsTrial()` 為真的前提就是
        // `introDiscountCents()` 為 null，而那也正是 `introDiscountCodeFor()`
        // 回 null 的條件。兩邊問的是同一個欄位，不可能同時成立。
        $withTrial = $this->grantsTrial($price, $eligible);
        $discountCode = $eligible && !$withTrial
            ? $this->introDiscountCodeFor($price, $subscription)
            : null;

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

        if ($discountCode !== null) {
            $payload['discount_code'] = $discountCode;
        }

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
     * 這筆結帳要不要送免費試用（14 天，`Subscription::TRIAL_DAYS`）。
     *
     * **月繳與年繳都送**，只要這個帳號還有資格。年繳曾經一度不送（改走首年
     * 折價），2026-10 owner 定案不降價、改成兩邊都 14 天試用之後回到這裡。
     *
     * 唯一的例外是**該 price 設了首期折價**：那時改走折扣碼那條路，不再送試用。
     * 判斷條件刻意寫成 `introDiscountCents() === null` 而不是「是不是年繳」——
     * 這讓「試用」與「折價」互斥變成一個欄位的兩面，不會因為日後有人改了其中
     * 一邊的條件而讓同一筆結帳同時拿到兩份優惠。
     *
     * 這個判斷之所以是獨立的方法而不是 `createCheckout()` 裡的一行：那條路要有
     * CREEM_SUCCESS_URL 跟一次 HTTP 往返才跑得到，而這裡決定的是使用者實際被收
     * 多少錢、什麼時候收，值得能被直接測。
     */
    public function grantsTrial(Price $price, bool $eligible): bool
    {
        return $eligible && $price->introDiscountCents() === null;
    }

    /**
     * 這個 price 該用哪一個 Creem product。
     *
     * 要送試用 → 用含 `trial_period_days` 的那一個；不送 → 用不含試用的。
     * **月繳與年繳都有這兩個變體**，都由 `creem:sync` 事先建好
     * （見 App\Console\Commands\Creem\Sync）。
     *
     * 找不到對應的 product 一律拋例外，**不要默默退回另一個變體**：讓沒資格的人
     * 拿到含試用的 product，就是把「訂閱 → 取消 → 再訂閱」的無限續杯洞打開；
     * 反過來讓有資格的人拿到不含試用的，則是當場就把他的錢收走。兩種都寧可
     * 結帳失敗也不要靜靜發生。
     *
     * 年繳的 trial variant 是 2026-10 才加回來的（中間那一版改走折價，刻意不建）。
     * 升級後第一次結帳年繳如果在這裡拋例外，代表那個環境的 `creems` 還缺年繳的
     * trial 列——跑一次 `creem:sync` 補上，`creem:verify` 會先指出來。
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
     * 這筆年繳結帳要掛的首年折扣碼；這個 price 沒有設首年優惠時回 null。
     *
     * **為什麼是「每筆訂閱一張單次折扣碼」而不是一張公用碼。** 折扣碼在 Creem 的
     * 結帳頁上是看得到、也可以手動輸入的。公用碼等於把「年繳折價終生一次」的
     * 閘門交給使用者保管：一個已經用過優惠的人取消再訂閱，手打那組碼就又便宜一次。
     * 所以這裡每筆訂閱發一張，並用兩道鎖把它釘死：
     *
     * - `max_redemptions: 1` —— 兌換過就死，任何人都不能再用第二次
     * - `expiry_date` +24 小時 —— 擋「開一堆結帳只為了把碼存起來、日後再訂閱時
     *   手動輸入」。碼本身沒被兌換時仍是活的，期限是唯一能收掉它的東西
     *
     * 碼是由訂閱 id 推導的，所以同一筆訂閱重試結帳會沿用同一張（先查再建）——
     * code 在 Creem 是唯一的，重複建會被擋。
     *
     * `applies_to_products` 一律指 standard product：走到折價這條路時就不送試用
     * （見 `grantsTrial()`），把折扣碼綁死在實際要收費的那個 product 上，才不會
     * 因為同一個 price 另有一個含試用的變體而讓這張碼跑去折到不該折的東西。
     */
    public function introDiscountCodeFor(Price $price, Subscription $subscription): ?string
    {
        $discountCents = $price->introDiscountCents();

        if ($discountCents === null || $discountCents <= 0) {
            return null;
        }

        $code = self::introCodeFor($subscription);
        $client = new CreemClient();

        if ($client->findDiscount($code) !== null) {
            return $code;
        }

        $client->createDiscount([
            // 後台只看得到 name，把方案與訂閱 id 寫進去才查得出這張是誰的。
            'name'     => sprintf('%s intro (%s)', $price->unit, (string) $subscription->getKey()),
            'code'     => $code,
            'type'     => 'fixed',
            'amount'   => $discountCents,
            'currency' => 'USD',
            // 只折第一筆付款，續約自動回到 product 原價——這就是「首年折價」。
            'duration'            => 'once',
            'applies_to_products' => [$this->productIdFor($price, false)],
            'max_redemptions'     => 1,
            'expiry_date'         => Carbon::now()->addDay()->toIso8601String(),
        ]);

        return $code;
    }

    /**
     * 這筆訂閱的首年折扣碼。
     *
     * 由訂閱 id 經 HMAC 推導而不是直接用 id：推導後是穩定的（重試結帳會拿到同一
     * 張碼，不會每次都在 Creem 建新的），但猜不出下一個人的碼——折扣碼在結帳頁
     * 可以手動輸入，能列舉就等於能繞過資格閘門。
     *
     * 長度取 12 個 hex 字元：Creem 後台與對帳單上要看得完，而 48 bits 的空間
     * 在我們這個量級不會撞。
     */
    public static function introCodeFor(Subscription $subscription): string
    {
        $secret = (string) env('APP_KEY', '');

        return 'INTRO' . mb_strtoupper(mb_substr(
            hash_hmac('sha256', (string) $subscription->getKey(), $secret),
            0,
            12
        ));
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

    /**
     * 付款完成、使用者被導回來時，當場開通訂閱——不必等 webhook。
     *
     * 與 Paddle 的 confirm() 同一個用意：Creem 原本完全靠 webhook 開通，webhook
     * 延遲、失敗，或本機開發收不到時，使用者付了錢卻用不到方案，而且因為訂閱
     * 從沒生效，連取消都找不到它。
     *
     * **只把前端帶來的 checkout_id 當索引，其餘一律向 Creem 查證**：checkout 是否
     * 真的 completed、對應的是哪一筆我們的訂閱（metadata.subscriptionId）、那筆
     * 訂閱是不是這個使用者的。redirect 網址是使用者可以任意改的，不能拿它當依據。
     *
     * 與 webhook 同時到也沒關係：syncFromCreem 是覆寫、rememberCreemSubscription
     * 是冪等的。
     *
     * @return null|Subscription 開通成功的訂閱；checkout 不存在、未完成、不屬於
     *                           這個使用者時為 null
     */
    public function confirmCheckout(string $userId, string $checkoutId): ?Subscription
    {
        if (!self::isCheckoutId($checkoutId)) {
            return null;
        }

        $client = new CreemClient();

        try {
            $checkout = $client->getCheckout($checkoutId);
        } catch (RuntimeException $e) {
            Log::warning('Could not retrieve a Creem checkout to confirm', [
                'checkout_id' => $checkoutId,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }

        $metadata = is_array($checkout['metadata'] ?? null) ? $checkout['metadata'] : [];
        $ourId = $metadata['subscriptionId'] ?? null;

        if (!is_string($ourId) || $ourId === '') {
            return null;
        }

        $subscription = Subscription::query()
            ->where('id', $ourId)
            ->where('user_id', $userId)
            ->where('payment_method', Subscription::PAYMENT_METHOD_CREEM)
            ->first();

        // 不屬於這個使用者：不開通，也不透露那筆訂閱存在與否。
        if (!$subscription) {
            return null;
        }

        if (($checkout['status'] ?? null) !== 'completed') {
            return null;
        }

        $creemSubscriptionId = self::subscriptionIdFromCheckout($checkout);

        if ($creemSubscriptionId === null) {
            return null;
        }

        $creemSubscription = $client->getSubscription($creemSubscriptionId);

        $this->syncFromCreem($subscription, $creemSubscription);
        $this->rememberCreemSubscription($subscription, $creemSubscription);

        return $subscription->refresh();
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
