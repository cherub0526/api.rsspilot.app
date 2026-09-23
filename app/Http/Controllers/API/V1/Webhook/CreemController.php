<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use Hypervel\Support\Facades\Log;
use App\OpenApi\Responses\Http400;
use App\Services\CreemSubscriptionService;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class CreemController extends AbstractController
{
    /** 結帳完成。訂閱狀態本身由後續的 subscription.* 事件帶來，這裡只記錄。 */
    public const string EVENT_CHECKOUT_COMPLETED = 'checkout.completed';

    /**
     * 訂閱生命週期事件，全部走同一段處理。
     *
     * `scheduled_cancel` 也收：使用者按下取消、但還要用到期末時 Creem 送這一則，
     * 訂閱物件會帶上 `canceled_at` 而狀態仍是 active。少收它的話，站內看不到
     * 「已排定取消」這件事，使用者會以為沒取消成功而重按或來信。
     */
    public const array SUBSCRIPTION_EVENTS = [
        'subscription.active',
        'subscription.paid',
        'subscription.trialing',
        'subscription.update',
        'subscription.canceled',
        'subscription.scheduled_cancel',
        'subscription.past_due',
        'subscription.unpaid',
        'subscription.expired',
        'subscription.paused',
    ];

    #[OAT\Post(
        path: '/v1/webhook/creem',
        operationId: 'api.v1.webhook.creem.store',
        summary: 'Receive Creem webhook events',
        tags: ['Webhook'],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function store(Request $request)
    {
        // 驗簽排在解析之前：未經認證的輸入連 json_decode 都不該做。
        $payload = (string) $request->getBody();

        $this->assertValidSignature($request, $payload);

        $params = json_decode($payload, true);

        if (!is_array($params)) {
            throw new InvalidRequestException(
                ['payload' => [__('validators.controllers.webhook.creem.malformed_payload')]]
            );
        }

        $eventType = (string) ($params['eventType'] ?? $params['event_type'] ?? '');
        $object = $params['object'] ?? null;

        if ($eventType === '' || !is_array($object)) {
            throw new InvalidRequestException(
                ['payload' => [__('validators.controllers.webhook.creem.malformed_payload')]]
            );
        }

        if (!in_array($eventType, self::SUBSCRIPTION_EVENTS, true)) {
            // 沒訂閱處理的事件（checkout.completed、refund.created、dispute.created…）
            // 一律回 200。回非 2xx 會讓 Creem 不斷重送一則我們本來就不打算處理的事件。
            return response()->make(self::RESPONSE_OK);
        }

        $service = new CreemSubscriptionService();
        $subscription = $service->resolveSubscription($object);

        if (!$subscription) {
            // 對不到訂閱就不要吞掉。回 422 讓 Creem 重送並留下可追的紀錄；
            // 安靜回 200 的話，資料從此對不起來而且沒有人會知道。
            Log::warning('Received a Creem subscription event for an unknown subscription', [
                'creem_subscription_id' => $object['id'] ?? null,
                'event_type'            => $eventType,
            ]);

            throw new InvalidRequestException(
                ['subscription' => [__('validators.controllers.subscription.not_found')]]
            );
        }

        $service->handleSubscriptionEvent($subscription, $object);

        return response()->make(self::RESPONSE_OK);
    }

    /**
     * 驗證 `creem-signature`：HMAC-SHA256(raw body, webhook secret)。
     *
     * **與 Paddle 的重要差異：Creem 的簽章不含時間戳**，所以這個標頭本身沒有任何
     * 重放保護——側錄到的合法請求可以無限次重送。緩解靠的是下游處理的冪等性
     * （`syncFromCreem` 是覆寫而非累加，`isStale()` 會擋掉舊事件），而不是這一層。
     *
     * 比對用 `hash_equals()` 而不是 `===`：後者的比較時間與相同前綴長度相關，
     * 會洩漏出可以逐位元組猜出簽章的資訊。
     *
     * 密鑰沒設時一律拒絕，不是放行——漏設的代價是「訂閱狀態不會更新」，很吵但
     * 看得見；放行的代價是「任何人都能改訂閱狀態」，安靜且危險。
     *
     * @throws InvalidRequestException
     */
    private function assertValidSignature(Request $request, string $payload): void
    {
        $secret = (string) env('CREEM_WEBHOOK_SECRET', '');

        if ($secret === '') {
            Log::warning('CREEM_WEBHOOK_SECRET is not set; rejecting the Creem webhook');

            $this->rejectSignature();
        }

        $received = (string) $request->header('creem-signature', '');

        if ($received === '') {
            $this->rejectSignature();
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $received)) {
            $this->rejectSignature();
        }
    }

    /**
     * 所有失敗原因回同一則訊息：告訴對方是「缺標頭」還是「雜湊不符」，
     * 等於免費送出試探用的資訊。
     *
     * @throws InvalidRequestException
     */
    private function rejectSignature(): never
    {
        throw new InvalidRequestException(
            ['signature' => ['Invalid Creem webhook signature.']]
        );
    }
}
