<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\User;
use App\Models\Clink;
use App\Models\Price;
use RuntimeException;
use App\Models\Subscription;
use Hypervel\Support\Facades\Log;

/**
 * Clink（Merchant of Record）這條金流的業務邏輯。公開方法的簽章與
 * CreemSubscriptionService 平行，SubscriptionsController 只需多一個 match 分支。
 *
 * 結構上的特點：
 *
 * 1. **轉址型結帳**。`POST /checkout/session` 回一個 `url`，付完導回
 *    CLINK_SUCCESS_URL，Clink 會在後面附加 `sessionId`。
 * 2. **Product → 多 price**。Plan 對到 product，Price 對到 product 底下的 price。
 * 3. **試用期綁在 price 上、結帳時不可覆寫**。「試用只送第一次」靠同一個 Price
 *    在 Clink 建兩個 price（standard / trial）、結帳時依資格挑，見 `priceIdFor()`。
 */
class ClinkSubscriptionService
{
    /**
     * 建立結帳。回傳形狀與 Creem 平行：`[供應商名 => [...], 'customData' => [...]]`，
     * 前端依「回應裡有哪個 key」分派。
     *
     * @return array<string, mixed>
     */
    public function createCheckout(User $user, Plan $plan, Price $price, Subscription $subscription): array
    {
        $successUrl = (string) env('CLINK_SUCCESS_URL', '');

        if ($successUrl === '') {
            throw new RuntimeException('CLINK_SUCCESS_URL is not configured.');
        }

        $eligible = (new SubscriptionService())->isEligibleForIntroOffer(
            (string) $subscription->user_id,
            (string) $subscription->getKey()
        );

        // 首期折價（first_period_price）在 Clink 還沒實作，目前也沒有任何 price 設它。
        // 哪天有人填了又走到 Clink，寧可結帳失敗，也不要定價頁寫著首期折價、
        // 結帳卻收原價。
        if ($eligible && $price->introDiscountCents() !== null) {
            throw new RuntimeException('Clink checkout does not support first_period_price yet.');
        }

        // Price::trialDays() 已經包含「免費方案不送」與「設了首期折價不送」，
        // 它就是送不送試用的單一事實來源。
        $withTrial = $eligible && $price->trialDays() !== null;
        $ourId = (string) $subscription->getKey();

        $payload = [
            'customerEmail'       => $user->email,
            'referenceCustomerId' => (string) $user->id,
            'productId'           => $this->productIdFor($plan),
            'priceId'             => $this->priceIdFor($price, $withTrial),
            // 主幣別：US$12.99 送 12.99。prices.price 是未稅 USD。
            'originalAmount'   => (float) $price->price,
            'originalCurrency' => 'USD',
            // merchantReferenceId 不是冪等鍵（同值送兩次得到兩個 session），只拿來對帳。
            'merchantReferenceId' => $ourId,
            'metadata'            => ['subscriptionId' => $ourId],
            'uiMode'              => 'hostedPage',
            'successUrl'          => $successUrl,
        ];

        $cancelUrl = (string) env('CLINK_CANCEL_URL', '');

        if ($cancelUrl !== '') {
            $payload['cancelUrl'] = $cancelUrl;
        }

        $client = new ClinkClient();
        $session = $client->createCheckoutSession($payload);

        $checkoutUrl = $session['url'] ?? null;
        $sessionId = $session['sessionId'] ?? null;

        if (!is_string($checkoutUrl) || $checkoutUrl === '' || !is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Clink did not return a checkout url and session id.');
        }

        $subscription->clink()->create([
            'foreign_type' => Subscription::class,
            'clink_id'     => $sessionId,
            'clink_detail' => $session,
            'variant'      => $withTrial ? Clink::VARIANT_TRIAL : Clink::VARIANT_STANDARD,
        ]);

        return [
            'clink' => [
                'checkout_url' => $checkoutUrl,
                'session_id'   => $sessionId,
                'test_mode'    => $client->isTestMode(),
            ],
            'customData' => ['subscriptionId' => $ourId],
        ];
    }

    /**
     * 這個 price 該用哪一個 Clink price。
     *
     * 找不到對應一律拋例外，**不退回另一個變體**：讓沒資格的人拿到含試用的 price
     * 是打開「訂閱 → 取消 → 再訂閱」的無限續杯洞；讓有資格的人拿到不含試用的，
     * 是當場把他的錢收走。兩種都寧可結帳失敗。
     */
    public function priceIdFor(Price $price, bool $withTrial): string
    {
        $variant = $withTrial ? Clink::VARIANT_TRIAL : Clink::VARIANT_STANDARD;

        /** @var null|Clink $clink */
        $clink = $price->clink()->where('variant', $variant)->first();

        if (!$clink || !$clink->clink_id) {
            throw new RuntimeException(sprintf(
                'Price %s has no Clink price for the "%s" variant. Run `php artisan clink:sync`.',
                (string) $price->getKey(),
                $variant
            ));
        }

        return $clink->clink_id;
    }

    /**
     * Clink 的訂閱狀態 → 我們自己的 `status`。寫錯的下場是權限問題，不是顯示問題。
     *
     * - `past_due` → `active`：Clink 還在重試，這段期間停權等於卡片過期就讓付費客人斷線
     * - `incomplete` → `paying`：首期沒付完，不給權限
     * - `cancelled`、`incomplete_expired` 與認不得的狀態 → `canceled`（fail closed）
     */
    public function statusFor(string $clinkStatus): string
    {
        return match ($clinkStatus) {
            'free_trial' => Subscription::STATUS_TRIAL,
            'active', 'past_due' => Subscription::STATUS_ACTIVE,
            'incomplete' => Subscription::STATUS_PAYING,
            default      => Subscription::STATUS_CANCELED,
        };
    }

    /**
     * 照 Clink 回報的訂閱寫回我們自己的訂閱。覆寫而非累加，重跑幾次結果都一樣。
     *
     * @param array<string, mixed> $clinkSubscription
     */
    public function syncFromClink(Subscription $subscription, array $clinkSubscription): void
    {
        $clinkStatus = (string) ($clinkSubscription['status'] ?? '');
        $attributes = ['status' => $this->statusFor($clinkStatus)];

        if ($createdAt = self::fromMs($clinkSubscription['createTime'] ?? null)) {
            $attributes['start_date'] = $createdAt;
        }

        // 本期終點：試用中是 trialEnd；其他看 recurringInvoiceItem.periodEnd，沒有才退
        // currentPeriodEnd。currentPeriodStart 建立後就不變，不能拿來推算本期。
        // 拿不到值時保留原值：寫成 null 會讓 scopeActive() 把試用當成永遠有效。
        $recurringItem = is_array($clinkSubscription['recurringInvoiceItem'] ?? null)
            ? $clinkSubscription['recurringInvoiceItem']
            : [];
        $periodEnd = $clinkStatus === 'free_trial'
            ? self::fromMs($clinkSubscription['trialEnd'] ?? null)
            : self::fromMs($recurringItem['periodEnd'] ?? null) ?? self::fromMs($clinkSubscription['currentPeriodEnd'] ?? null);

        if ($periodEnd) {
            $attributes['next_date'] = $periodEnd;
        }

        // 已排定期末取消或已結束時記下取消時間；兩者都不成立代表取消被撤回，清掉。
        $attributes['cancellation_date'] = ($clinkSubscription['cancelAtPeriodEnd'] ?? false) === true || $clinkStatus === 'cancelled'
            ? self::fromMs($clinkSubscription['canceledAt'] ?? null) ?? $subscription->cancellation_date ?? Carbon::now()
            : null;

        $subscription->fill($attributes)->save();
    }

    /**
     * 從 webhook 的 `data.object` 找出這是我們哪一筆訂閱。順序：.
     *
     * 1. `metadata.subscriptionId`：結帳時塞進去的
     * 2. `merchantReference` / `merchantReferenceId`：同一個值的另一條路
     * 3. `clinks` 表以 `subscriptionId` 或 `sessionId` 反查：invoice 物件沒有 metadata
     *
     * @param array<string, mixed> $object
     */
    public function resolveSubscription(array $object): ?Subscription
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        foreach ([$metadata['subscriptionId'] ?? null, $object['merchantReference'] ?? null, $object['merchantReferenceId'] ?? null] as $ourId) {
            if (!is_string($ourId) || $ourId === '') {
                continue;
            }

            /** @var null|Subscription $subscription */
            $subscription = Subscription::query()
                ->where('id', $ourId)
                ->where('payment_method', Subscription::PAYMENT_METHOD_CLINK)
                ->first();

            if ($subscription) {
                return $subscription;
            }
        }

        $clinkIds = array_values(array_filter(
            [$object['subscriptionId'] ?? null, $object['sessionId'] ?? null],
            fn ($id) => is_string($id) && $id !== ''
        ));

        if ($clinkIds === []) {
            return null;
        }

        /** @var null|Clink $clink */
        $clink = Clink::query()
            ->where('foreign_type', Subscription::class)
            ->whereIn('clink_id', $clinkIds)
            ->first();

        /** @var null|Subscription */
        return $clink ? Subscription::query()->find($clink->foreign_id) : null;
    }

    /**
     * 所有處理中的 webhook 事件共用：**事件只當通知，狀態以 API 為準**。
     *
     * Clink 不保證送達順序，subscription 物件也沒有 updated_at 可以比新舊。回頭打
     * `GET /subscription/{id}` 拿當下最新狀態再覆寫，不管事件怎麼亂序、重送幾次，
     * 寫進去的都是同一個結果。API 失敗時例外往外拋，webhook 回 5xx，Clink 會重送。
     *
     * @param array<string, mixed> $object
     */
    public function handleEvent(Subscription $subscription, array $object): void
    {
        $clinkSubscriptionId = $object['subscriptionId'] ?? null;

        if (!is_string($clinkSubscriptionId) || $clinkSubscriptionId === '') {
            $clinkSubscriptionId = $this->resolveClinkSubscriptionId($subscription);
        }

        if ($clinkSubscriptionId === null) {
            Log::warning('A Clink event matched a subscription that has no Clink subscription yet', [
                'subscription_id' => (string) $subscription->getKey(),
            ]);

            return;
        }

        $this->syncSubscription($subscription, $clinkSubscriptionId);
    }

    /**
     * 找出這筆訂閱在 Clink 上的訂閱 id。
     *
     * 結帳當下 `clinks` 列存的是 session id（那時還沒有訂閱），第一次同步後才改寫成
     * subscription id。手上只有 session id 時回頭查 session，有訂閱就改寫並回傳；
     * 沒付完款的 session 沒有訂閱，回 null。
     */
    public function resolveClinkSubscriptionId(Subscription $subscription): ?string
    {
        /** @var null|Clink $clink */
        $clink = $subscription->clink()->whereNotNull('clink_id')->first();

        if (!$clink || !$clink->clink_id) {
            return null;
        }

        if (!self::isSessionId($clink->clink_id)) {
            return $clink->clink_id;
        }

        $session = (new ClinkClient())->getCheckoutSession($clink->clink_id);
        $clinkSubscriptionId = $session['subscriptionId'] ?? null;

        if (!is_string($clinkSubscriptionId) || $clinkSubscriptionId === '') {
            return null;
        }

        $clink->fill(['clink_id' => $clinkSubscriptionId])->save();

        return $clinkSubscriptionId;
    }

    /** Checkout session id 以 sess_ 開頭；訂閱 id 是 sub_。 */
    public static function isSessionId(string $clinkId): bool
    {
        return str_starts_with($clinkId, 'sess_');
    }

    private function syncSubscription(Subscription $subscription, string $clinkSubscriptionId): void
    {
        $clinkSubscription = (new ClinkClient())->getSubscription($clinkSubscriptionId);

        $this->syncFromClink($subscription, $clinkSubscription);
        $this->rememberClinkSubscription($subscription, $clinkSubscriptionId, $clinkSubscription);
    }

    /**
     * 記住這筆訂閱對應的 Clink 訂閱：已有同一個 clink_id 就只刷新 detail，否則改寫
     * 結帳時那一列（sess_ → sub_）。不另插新列，事件會反覆進來。
     *
     * @param array<string, mixed> $clinkSubscription
     */
    private function rememberClinkSubscription(Subscription $subscription, string $clinkSubscriptionId, array $clinkSubscription): void
    {
        /** @var null|Clink $existing */
        $existing = $subscription->clink()->where('clink_id', $clinkSubscriptionId)->first()
            ?? $subscription->clink()->first();

        if ($existing) {
            $existing->fill(['clink_id' => $clinkSubscriptionId, 'clink_detail' => $clinkSubscription])->save();

            return;
        }

        $subscription->clink()->create([
            'foreign_type' => Subscription::class,
            'clink_id'     => $clinkSubscriptionId,
            'clink_detail' => $clinkSubscription,
        ]);
    }

    /** Clink 的時間戳一律當毫秒處理（webhook 範例全是 13 位數）。只在這一層轉換。 */
    private static function fromMs(mixed $value): ?Carbon
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Carbon::createFromTimestampMs((int) $value);
        }

        return null;
    }

    private function productIdFor(Plan $plan): string
    {
        /** @var null|Clink $clink */
        $clink = $plan->clink()->first();

        if (!$clink || !$clink->clink_id) {
            throw new RuntimeException(sprintf(
                'Plan %s has no Clink product. Run `php artisan clink:sync`.',
                (string) $plan->getKey()
            ));
        }

        return $clink->clink_id;
    }
}
