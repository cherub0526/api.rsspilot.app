<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\Clink;
use App\Models\Price;
use RuntimeException;
use App\Models\Subscription;

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
