<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 結帳的限流必須有自己的計數（hidden-plan-link T03 卡〈紀錄〉）。
 *
 * ThrottleRequests 的 key 只有 prefix 加上 user id：沒有 prefix 的話，其他也掛 throttle
 * 的路由（例如 sources preview 的 throttle:30,1）會把結帳的 10 次一起用掉，
 * 正常結帳就被 429 擋住。
 *
 * @internal
 * @coversNothing
 */
class SubscriptionsThrottlePrefixTest extends TestCase
{
    use RefreshDatabase;

    public function testOtherThrottledRoutesDoNotUseUpTheCheckoutLimit(): void
    {
        Http::fake();
        $this->fakeLogin();

        for ($i = 0; $i < 30; ++$i) {
            $this->json('GET', route('api.v1.sources.preview.index'));
        }
        $this->json('GET', route('api.v1.sources.preview.index'))->assertStatus(429);

        // planId 不存在：被擋在方案檢查，不會結帳；重點是不能先被 429 擋下
        $this->json('POST', route('api.v1.subscriptions.store'), [
            'planId'  => '01JCXYZ123456789ABCDEFGHIJ',
            'priceId' => '01JCXYZ123456789ABCDEFGHIJ',
        ])->assertStatus(422);
    }
}
