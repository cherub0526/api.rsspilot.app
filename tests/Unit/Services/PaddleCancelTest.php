<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Mockery;
use Tests\TestCase;
use App\Models\Paddle;
use App\Models\Subscription;
use App\Services\PaddleClient;
use App\Services\PaddleSubscriptionService;
use Paddle\SDK\Resources\Subscriptions\SubscriptionsClient;
use Paddle\SDK\Resources\Subscriptions\Operations\CancelSubscription;

/**
 * 取消 Paddle 訂閱時送給 SDK 的參數。
 *
 * SDK 的 `SubscriptionsClient::cancel()` 要兩個參數（id 與 CancelSubscription），
 * 曾經只傳 id，PHP 直接拋 ArgumentCountError，Paddle 訂閱戶按取消一律 500。
 * 真的打 API 測不到這件事，所以注入假的 PaddleClient，只驗證送出去的內容。
 *
 * @internal
 * @coversNothing
 */
class PaddleCancelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 取消排在期末，與 Creem 的 `mode=scheduled` 一致：使用者已付的這一期要用到滿。
     * 退款政策與設定頁的文案都是這樣承諾的。
     */
    public function testCancelSchedulesAtTheEndOfTheBillingPeriod(): void
    {
        $sent = [];
        $subscriptions = Mockery::mock(SubscriptionsClient::class);
        $subscriptions->shouldReceive('cancel')
            ->once()
            ->andReturnUsing(function (string $id, CancelSubscription $operation) use (&$sent) {
                $sent = [$id, $operation];

                return Mockery::mock(\Paddle\SDK\Entities\Subscription::class);
            });

        $client = Mockery::mock(PaddleClient::class);
        $client->shouldReceive('subscriptions')->andReturn($subscriptions);

        $subscription = new Subscription();
        $subscription->setRelation('paddle', new Paddle(['paddle_id' => 'sub_paddle_123']));

        (new PaddleSubscriptionService($client))->cancel($subscription);

        [$id, $operation] = $sent;
        $this->assertSame('sub_paddle_123', $id);
        $this->assertSame('next_billing_period', $operation->jsonSerialize()['effective_from']?->getValue());
    }
}
