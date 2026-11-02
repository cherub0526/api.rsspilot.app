<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use Hypervel\Support\Facades\Log;
use App\Services\ClinkWebhookSignature;
use Psr\Http\Message\ResponseInterface;
use App\Services\ClinkSubscriptionService;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class ClinkController extends AbstractController
{
    /**
     * 會觸發同步的事件。在 Clink dashboard 註冊 endpoint 時要逐一勾選（不接受
     * `subscription.*` 萬用字元），`clink:verify` 讀同一份清單比對。
     *
     * 不收 `subscription.created`：它只代表紀錄存在，不是開通訊號。不收 `order.*`：
     * 那是一次性付款的模型，訂閱以 subscriptionId 為準。只聽 activated 會漏續約
     * （一般續約只送 renewed），只聽 renewed 會漏試用轉付費與催收成功。
     */
    public const array HANDLED_EVENTS = [
        'subscription.trialing',
        'subscription.activated',
        'subscription.past_due',
        'subscription.cancelled',
        'subscription.incomplete_expired',
        'subscription.updated.renewed',
        'subscription.updated.cancel_at_period_end_set',
        'subscription.updated.cancel_at_period_end_revoked',
        'subscription.updated.plan_changed',
        'subscription.updated.plan_change_canceled',
        'invoice.paid',
    ];

    #[OAT\Post(
        path: '/v1/webhook/clink',
        operationId: 'api.v1.webhook.clink.store',
        summary: 'Receive Clink webhook events',
        tags: ['Webhook'],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(response: 422, description: 'Invalid signature, malformed payload, or unknown subscription'),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function store(Request $request): ResponseInterface
    {
        // 驗簽排在解析之前：未經認證的輸入連 json_decode 都不該做。
        $payload = (string) $request->getBody();

        $this->assertValidSignature($request, $payload);

        $event = json_decode($payload, true);
        $type = is_array($event) ? ($event['type'] ?? null) : null;
        $object = is_array($event) && is_array($event['data'] ?? null) ? ($event['data']['object'] ?? null) : null;

        if (!is_string($type) || $type === '' || !is_array($object)) {
            throw new InvalidRequestException(
                ['payload' => [__('validators.controllers.webhook.clink.malformed_payload')]]
            );
        }

        if (!in_array($type, self::HANDLED_EVENTS, true)) {
            // 回非 2xx 會讓 Clink 不斷重送一則我們本來就不打算處理的事件。
            return response()->make(self::RESPONSE_OK);
        }

        $service = new ClinkSubscriptionService();
        $subscription = $service->resolveSubscription($object);

        if (!$subscription) {
            // 不要吞掉：回 422 讓 Clink 重送並留下可追的紀錄。
            Log::warning('Received a Clink event for an unknown subscription', [
                'event_id'              => $event['id'] ?? null,
                'event_type'            => $type,
                'clink_subscription_id' => $object['subscriptionId'] ?? null,
            ]);

            throw new InvalidRequestException(
                ['subscription' => [__('validators.controllers.subscription.not_found')]]
            );
        }

        if ($type === 'subscription.updated.plan_changed') {
            // 我們的 app 沒有換方案流程，Customer Portal 的升降級也要關掉。真的發生時
            // 只同步狀態，plan_id / price_id 不會跟著變，需要人工處理。
            Log::warning('A Clink subscription changed plans; plan_id was not updated', [
                'subscription_id' => (string) $subscription->getKey(),
                'clink_price_id'  => $object['priceId'] ?? null,
            ]);
        }

        $service->handleEvent($subscription, $object);

        return response()->make(self::RESPONSE_OK);
    }

    /**
     * 密鑰沒設時一律拒絕，不是放行：漏設的代價是「訂閱狀態不會更新」，很吵但看得見；
     * 放行的代價是「任何人都能改訂閱狀態」。
     *
     * @throws InvalidRequestException
     */
    private function assertValidSignature(Request $request, string $payload): void
    {
        $valid = ClinkWebhookSignature::isValid(
            signType: (string) $request->header('X-Clink-SignType', ''),
            timestamp: (string) $request->header('X-Clink-Timestamp', ''),
            signature: (string) $request->header('X-Clink-Signature', ''),
            payload: $payload,
            secret: (string) env('CLINK_WEBHOOK_SECRET', ''),
            nowMs: (int) floor(microtime(true) * 1000),
        );

        if ($valid) {
            return;
        }

        // 所有失敗原因回同一則訊息，不透露是哪一項不符。
        throw new InvalidRequestException(['signature' => ['Invalid Clink webhook signature.']]);
    }
}
