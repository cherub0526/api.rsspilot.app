<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use Throwable;
use Carbon\Carbon;
use Hypervel\Http\Request;
use App\Models\Transaction;
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
                        enum: ['transaction.completed'],
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

            // price 上帶著一個月的 trial_period，對每個結帳的人都一樣。首月免費
            // 只送第一次這條規則 Paddle 不知道，沒有資格的人要在這裡當場啟用計費
            // （見 PaddleSubscriptionService::applyFreeMonth()），再照 Paddle 回報
            // 的狀態與日期寫入。
            $service = new PaddleSubscriptionService();
            $paddleSubscription = $service->applyFreeMonth(
                $subscription,
                $paddleClient->subscriptions()->get($paddleTransaction->subscriptionId)
            );

            $service->syncFromPaddle($subscription, $paddleSubscription);

            if (!$subscription->paddle()->where(['paddle_id' => $paddleTransaction->subscriptionId])->first()) {
                $subscription->paddle()->create([
                    'paddle_id'     => $paddleSubscription->id,
                    'paddle_detail' => $paddleSubscription,
                    'foreign_type'  => Subscription::class,
                ]);
            }

            $transactionPaddle = $subscription->transactions()->whereHas(
                'paddle',
                function ($builder) use ($paddleTransaction) {
                    $builder->where('paddle_id', $paddleTransaction->id);
                }
            )->first();

            if (!$transactionPaddle) {
                $transactionPaddle = $subscription->transactions()->create([
                    'billing_date' => Carbon::parse($paddleTransaction->billedAt),
                    'amount'       => floatval($paddleTransaction->details->totals->total) / 100,
                    'status'       => TransactionStatus::Completed()->getValue(),
                ]);

                $transactionPaddle->paddle()->create([
                    'paddle_id'     => $paddleTransaction->id,
                    'paddle_detail' => $paddleTransaction,
                    'foreign_type'  => Transaction::class,
                ]);
            }

            return response()->make(self::RESPONSE_OK);
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
