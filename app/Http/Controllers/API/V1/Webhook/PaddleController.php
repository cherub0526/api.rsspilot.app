<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use Throwable;
use Hypervel\Http\Request;
use App\Models\Subscription;
use App\Services\PaddleClient;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use Hypervel\Support\Facades\Log;
use App\OpenApi\Responses\Http400;
use Paddle\SDK\Exceptions\ApiError;
use Paddle\SDK\Notifications\Secret;
use App\Services\PaddleWebhookIpAllowlist;
use App\Exceptions\InvalidRequestException;
use App\Services\PaddleSubscriptionService;
use App\Http\Controllers\AbstractController;
use Paddle\SDK\Notifications\PaddleSignature;
use App\Validators\PaddleTransactionValidator;
use Paddle\SDK\Entities\Shared\TransactionStatus;
use Paddle\SDK\Exceptions\SdkExceptions\MalformedResponse;

class PaddleController extends AbstractController
{
    /** 首購與續訂扣款成功。訂閱與交易兩邊的資料都由這一則補齊。 */
    public const EVENT_TRANSACTION_COMPLETED = 'transaction.completed';

    /**
     * 扣款失敗。**只記錄、不改狀態**——這時 Paddle 才剛開始 dunning，真正的
     * 狀態變化會由後續的 `subscription.past_due` / `subscription.canceled` 帶來。
     * 在這裡就把人停權，等於卡片過期立刻失去服務。
     */
    public const EVENT_TRANSACTION_PAYMENT_FAILED = 'transaction.payment_failed';

    /**
     * 六種訂閱生命週期事件，全部走同一段處理（見
     * PaddleSubscriptionService::handleSubscriptionEvent()）。
     */
    public const SUBSCRIPTION_EVENTS = [
        'subscription.activated',
        'subscription.updated',
        'subscription.canceled',
        'subscription.past_due',
        'subscription.paused',
        'subscription.resumed',
    ];

    /**
     * 驗證層的白名單：沒列在這裡的事件一律擋在門外。
     *
     * 從 SUBSCRIPTION_EVENTS 展開而不是再抄一份——兩份清單遲早會漂移，而漂移的
     * 症狀是「Paddle 那邊訂閱了，我們這邊在驗證層默默擋掉」，只有上線後才看得到。
     */
    public const HANDLED_EVENTS = [
        self::EVENT_TRANSACTION_COMPLETED,
        self::EVENT_TRANSACTION_PAYMENT_FAILED,
        ...self::SUBSCRIPTION_EVENTS,
    ];

    /** 簽章容許的時間差（秒）。SDK 預設 5 秒太緊，這裡對齊 Stripe 的 300 秒。 */
    private const SIGNATURE_TOLERANCE = 300;

    #[OAT\Post(
        path: '/v1/webhook/paddle',
        operationId: 'api.v1.webhook.paddle.store',
        summary: 'Receive Paddle transaction webhook callback',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['event_id', 'event_type', 'occurred_at', 'notification_id', 'data'],
                properties: [
                    new OAT\Property(property: 'event_id', type: 'string', example: 'evt_01h8bzakzx3nhsf0rr6jh6vj6g'),
                    new OAT\Property(
                        property: 'event_type',
                        type: 'string',
                        enum: PaddleController::HANDLED_EVENTS,
                        example: 'transaction.completed'
                    ),
                    new OAT\Property(
                        property: 'occurred_at',
                        type: 'string',
                        format: 'date-time',
                        example: '2024-01-01T00:00:00Z'
                    ),
                    new OAT\Property(
                        property: 'notification_id',
                        type: 'string',
                        example: 'ntf_01h8bzakzx3nhsf0rr6jh6vj6g'
                    ),
                    new OAT\Property(
                        property: 'data',
                        required: ['id'],
                        properties: [
                            new OAT\Property(property: 'id', type: 'string', example: 'txn_01h8bzakzx3nhsf0rr6jh6vj6g'),
                        ],
                        type: 'object'
                    ),
                ]
            )
        ),
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
        // 驗簽排在驗證之前：未經認證的輸入連解析都不該做。少了這一步，任何人
        // 都能對這個端點送請求，讓伺服器替他去 Paddle 查一輪，而且它會改訂閱狀態。
        $this->assertAllowedIp($request);
        $this->assertValidSignature($request);

        $params = $request->all();

        $v = new PaddleTransactionValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $eventType = (string) $params['event_type'];

        if ($eventType === self::EVENT_TRANSACTION_PAYMENT_FAILED) {
            // 狀態不動（見常數上的說明），但一定要留下痕跡：這是客人開始扣不到
            // 錢的第一個訊號，之後要追「為什麼這個人掉了」就靠它。
            Log::notice('Paddle reported a failed payment', [
                'transaction_id' => $params['data']['id'],
                'event_id'       => $params['event_id'],
            ]);

            return response()->make(self::RESPONSE_OK);
        }

        if (in_array($eventType, self::SUBSCRIPTION_EVENTS, true)) {
            $this->handleSubscriptionEvent($params);

            return response()->make(self::RESPONSE_OK);
        }

        $this->handleTransactionCompleted($params);

        return response()->make(self::RESPONSE_OK);
    }

    /**
     * `subscription.*`：找出是哪一筆訂閱，剩下的交給 service 跟 Paddle 對答案。
     *
     * @throws InvalidRequestException
     */
    private function handleSubscriptionEvent(array $params): void
    {
        $service = new PaddleSubscriptionService();

        $subscription = $service->resolveSubscription(
            (string) $params['data']['id'],
            $this->customDataSubscriptionId($params)
        );

        if (!$subscription) {
            // 對不到訂閱就不要吞掉。回 422 會讓 Paddle 重送，留下可以追的紀錄；
            // 安靜回 200 的話，資料從此對不起來而且沒有人會知道。
            Log::warning('Received a Paddle subscription event for an unknown subscription', [
                'paddle_subscription_id' => $params['data']['id'],
                'event_type'             => $params['event_type'],
            ]);

            throw new InvalidRequestException(
                ['subscription' => [__('validators.controllers.subscription.not_found')]]
            );
        }

        if (!$service->handleSubscriptionEvent($subscription, (string) $params['data']['id'])) {
            // 跟 Paddle 對答案時失敗（多半是一時的網路或 5xx）。這時**不能**回 200：
            // 這一則帶著的狀態變化就永遠不會再來，訂閱會停在舊狀態。回非 2xx 讓
            // Paddle 走它自己的重送機制。
            throw new InvalidRequestException(
                ['subscription' => [__('validators.controllers.webhook.paddle.sync_failed')]]
            );
        }
    }

    /**
     * 結帳時塞進去的 `customData.subscriptionId`，用來在 `paddles` 還沒有對應列
     * 的時候找到訂閱。Paddle 對不同事件放的位置不一樣，兩個地方都看。
     */
    private function customDataSubscriptionId(array $params): ?string
    {
        $data = $params['data'] ?? [];

        $customData = $data['custom_data']
            ?? $data['subscription']['custom_data']
            ?? null;

        if (!is_array($customData)) {
            return null;
        }

        $id = $customData['subscriptionId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * `transaction.completed`：確認交易真的完成，找出訂閱，其餘交給 service。
     *
     * 這裡仍然回頭跟 Paddle 查一次交易，而不是相信 payload——驗簽只證明「這則
     * 通知確實來自 Paddle」，不證明「此刻這筆交易仍是完成狀態」。
     *
     * @throws InvalidRequestException
     */
    private function handleTransactionCompleted(array $params): void
    {
        $paddleClient = new PaddleClient();

        try {
            $paddleTransaction = $paddleClient->transactions()->get($params['data']['id']);

            if ($paddleTransaction->status->getValue() !== TransactionStatus::Completed()->getValue()) {
                throw new InvalidRequestException(
                    ['transaction' => [__('validators.controllers.webhook.paddle.transaction_not_completed')]]
                );
            }

            if (!$subscription = Subscription::query()->find($paddleTransaction->customData->data['subscriptionId'])) {
                throw new InvalidRequestException(
                    ['subscription' => [__('validators.controllers.subscription.not_found')]]
                );
            }

            (new PaddleSubscriptionService())->handleTransactionCompleted($subscription, $paddleTransaction);
        } catch (ApiError $e) {
        } catch (MalformedResponse $e) {
        }
    }

    /**
     * 來源 IP 檢查（縱深防禦，主要防線仍是下面的驗簽）。
     *
     * **預設關閉**，要靠 PADDLE_WEBHOOK_IP_ALLOWLIST=true 才會生效。這不是保守
     * 過頭：這個服務跑在反向代理後面（Railway），`remote_addr` 看到的是代理的
     * IP 而不是 Paddle 的，貿然開啟會把**每一則** webhook 都擋掉，而且症狀是
     * 「訂閱莫名其妙不會生效」。開之前先看一次下面那行 log 確認我們到底收到什麼 IP。
     *
     * 代理會把真正的來源放在 X-Forwarded-For 的最左邊，但那個標頭是外部可寫的，
     * 只有在確定前面那層代理會覆寫它時才可信——所以要用它得再開
     * PADDLE_WEBHOOK_TRUSTED_PROXY=true，兩個旗標分開，避免「想開 IP 檢查」
     * 不小心連「相信一個偽造得了的標頭」一起開下去。
     *
     * @throws InvalidRequestException
     */
    private function assertAllowedIp(Request $request): void
    {
        if (!filter_var(env('PADDLE_WEBHOOK_IP_ALLOWLIST', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $ip = (string) ($request->getServerParams()['remote_addr'] ?? '');

        if (filter_var(env('PADDLE_WEBHOOK_TRUSTED_PROXY', false), FILTER_VALIDATE_BOOLEAN)) {
            $forwarded = (string) $request->header('X-Forwarded-For', '');

            if ($forwarded !== '') {
                $ip = trim(explode(',', $forwarded)[0]);
            }
        }

        if ($ip === '' || !(new PaddleWebhookIpAllowlist())->allows($ip)) {
            Log::warning('Rejected a Paddle webhook from an IP outside the allowlist', ['ip' => $ip]);

            throw new InvalidRequestException(
                ['ip' => [__('validators.controllers.webhook.paddle.ip_not_allowed')]]
            );
        }
    }

    /**
     * 驗證 Paddle-Signature。
     *
     * **不能用 SDK 的 `Notifications\Verifier`**：它會對請求 body 呼叫 `rewind()`，
     * 而 Swoole 的 `SwooleStream` 不可 seek，直接拋
     * `RuntimeException: Cannot seek a SwooleStream`，webhook 會全部變成 500。
     * 改用它底下的 `PaddleSignature`——雜湊演算法與協商仍然由 SDK 負責，只有
     * 「怎麼拿到 raw body」與「時間差」這兩件事自己處理。
     *
     * 容許時間差刻意放寬到 300 秒（SDK 預設只有 5 秒）：5 秒撐不住實務上的時鐘
     * 偏移與重送，會把合法的 webhook 擋掉；300 秒與 Stripe 的預設一致。兩個方向
     * 都檢查，未來時間戳同樣拒絕。
     *
     * 密鑰沒設時一律拒絕，不是放行——漏設的代價是「訂閱確認不了」，很吵但看得見；
     * 放行的代價是「任何人都能改訂閱狀態」，安靜且危險。另外記一行 log，讓維運
     * 分得出「設定漏了」與「有人在打」。
     *
     * @throws InvalidRequestException
     */
    private function assertValidSignature(Request $request): void
    {
        $secret = (string) env('PADDLE_WEBHOOK_SECRET_KEY', '');

        if ($secret === '') {
            Log::warning('PADDLE_WEBHOOK_SECRET_KEY is not set; rejecting the Paddle webhook');

            $this->rejectSignature();
        }

        try {
            // parse() 對無法辨識的 key 會拋 LogicException，verify() 對未知的雜湊
            // 演算法也會——標頭是攻擊者可控的輸入，不收斂的話就成了 500。
            $signature = PaddleSignature::parse((string) $request->header(PaddleSignature::HEADER, ''));

            if (abs(time() - $signature->timestamp) > self::SIGNATURE_TOLERANCE) {
                $this->rejectSignature();
            }

            $valid = $signature->verify((string) $request->getBody(), new Secret($secret));
        } catch (InvalidRequestException $e) {
            throw $e;
        } catch (Throwable) {
            $this->rejectSignature();
        }

        if (!$valid) {
            $this->rejectSignature();
        }
    }

    /**
     * 所有失敗原因回同一則訊息：告訴對方是「時間戳過期」還是「雜湊不符」，
     * 等於免費送出試探用的資訊。
     *
     * @throws InvalidRequestException
     */
    private function rejectSignature(): never
    {
        throw new InvalidRequestException(
            ['signature' => ['Invalid Paddle webhook signature.']]
        );
    }
}
