<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use RuntimeException;
use App\Services\ClinkClient;
use Hypervel\Support\Facades\Http;
use Hypervel\HttpClient\Request as HttpRequest;

/**
 * @internal
 * @coversNothing
 */
class ClinkClientTest extends TestCase
{
    /** 與 phpunit.xml.dist 的 CLINK_API_KEY 一致。 */
    private const API_KEY = 'sk_uat_clink_phpunit';

    public function testSendsTheKeyAndAFreshMillisecondTimestampOnEveryRequest(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['subscriptionId' => 'sub_1']))]);

        $client = new ClinkClient();
        $client->getSubscription('sub_1');
        usleep(2000);
        $client->getSubscription('sub_1');

        $timestamps = [];

        foreach (Http::recorded() as [$request]) {
            /** @var HttpRequest $request */
            $this->assertSame(self::API_KEY, $request->header('X-API-Key')[0]);
            $timestamp = $request->header('X-Timestamp')[0];
            $this->assertMatchesRegularExpression('/^\d{13}$/', $timestamp, 'X-Timestamp 是 13 位數的毫秒');
            $timestamps[] = $timestamp;
        }

        $this->assertCount(2, $timestamps);
        $this->assertNotSame($timestamps[0], $timestamps[1], 'X-Timestamp 每次請求現算，不可快取');
    }

    public function testTestModeTargetsTheUatHostUnderApi(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['sessionId' => 'sess_1']))]);

        (new ClinkClient())->getCheckoutSession('sess_1');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://uat-api.clinkbill.com/api/checkout/session/sess_1');
    }

    public function testLiveModeTargetsTheProductionHostUnderApi(): void
    {
        $this->withEnv('CLINK_TEST_MODE', 'false', function () {
            Http::fake(['*' => Http::response($this->envelope(['sessionId' => 'sess_1']))]);

            (new ClinkClient())->getCheckoutSession('sess_1');

            Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.clinkbill.com/api/checkout/session/sess_1');
        });
    }

    public function testReturnsTheDataOfTheEnvelope(): void
    {
        Http::fake(['*/subscription/sub_1' => Http::response($this->envelope(['subscriptionId' => 'sub_1', 'status' => 'active']))]);

        $this->assertSame(
            ['subscriptionId' => 'sub_1', 'status' => 'active'],
            (new ClinkClient())->getSubscription('sub_1')
        );
    }

    /** 官方範例同時檢查 HTTP 狀態與信封的 code；HTTP 200 但 code 不是 200 一樣是失敗。 */
    public function testThrowsWhenTheEnvelopeCodeIsNotSuccess(): void
    {
        Http::fake(['*' => Http::response(['code' => 400, 'msg' => 'CUSTOMER_IDENTIFIER_NOT_MATCHED', 'data' => null])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('code 400');

        (new ClinkClient())->createCheckoutSession(['productId' => 'prd_1']);
    }

    public function testThrowsWhenTheEnvelopeHasNoData(): void
    {
        Http::fake(['*' => Http::response(['code' => 200, 'msg' => 'success'])]);

        $this->expectException(RuntimeException::class);

        (new ClinkClient())->getPrice('price_1');
    }

    public function testThrowsOnANon2xxStatus(): void
    {
        Http::fake(['*' => Http::response(['code' => 500, 'msg' => 'boom'], 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new ClinkClient())->getSubscription('sub_1');
    }

    public function testThrowsWhenTheKeyIsMissing(): void
    {
        $this->withEnv('CLINK_API_KEY', '', function () {
            Http::fake();

            try {
                (new ClinkClient())->getSubscription('sub_1');
                $this->fail('沒設 CLINK_API_KEY 應該拋例外');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('CLINK_API_KEY', $e->getMessage());
            }

            Http::assertNothingSent();
        });
    }

    public function testEncodesPathParameters(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['subscriptionId' => 'x']))]);

        (new ClinkClient())->getSubscription('../price/1');

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/subscription/..%2Fprice%2F1'));
    }

    /** 取消預設是期末取消，reason 是 Clink 的必填欄位。 */
    public function testCancelDefaultsToPeriodEnd(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['subscriptionId' => 'sub_1']))]);

        (new ClinkClient())->cancelSubscription('sub_1', 'Customer requested cancellation');

        Http::assertSent(function (HttpRequest $request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/subscription/sub_1/cancel')
                && $request->data() === ['reason' => 'Customer requested cancellation', 'cancelImmediately' => false];
        });
    }

    public function testUpdatePriceUsesPut(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['priceId' => 'price_1']))]);

        (new ClinkClient())->updatePrice('price_1', ['unitAmount' => 12.99]);

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/price/price_1')
            && $request->data() === ['unitAmount' => 12.99]);
    }

    /** 列表端點的回應沒有 data，資料在 rows。 */
    public function testListsWebhookEndpointsFromRows(): void
    {
        Http::fake(['*/webhook/endpoints' => Http::response([
            'code'  => 200,
            'msg'   => 'success',
            'total' => 1,
            'rows'  => [['id' => 'whk_1', 'url' => 'https://api.example/v1/webhook/clink', 'enabled' => true]],
        ])]);

        $this->assertSame(
            [['id' => 'whk_1', 'url' => 'https://api.example/v1/webhook/clink', 'enabled' => true]],
            (new ClinkClient())->listWebhookEndpoints()
        );
    }

    public function testUploadsTheProductImageAsMultipart(): void
    {
        Http::fake(['*/product/image/upload' => Http::response($this->envelope(['ossId' => 'oss_1', 'url' => 'https://cdn/x.png']))]);

        $result = (new ClinkClient())->uploadProductImage(base_path('public/logo.png'));

        $this->assertSame('oss_1', $result['ossId']);

        Http::assertSent(fn (HttpRequest $request) => $request->isMultipart()
            && str_ends_with($request->url(), '/api/product/image/upload')
            && $request->hasFile('file', null, 'logo.png'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function envelope(array $data): array
    {
        return ['code' => 200, 'msg' => 'success', 'data' => $data];
    }

    private function withEnv(string $key, string $value, callable $callback): void
    {
        $previous = getenv($key);
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;

        try {
            $callback();
        } finally {
            putenv($previous === false ? $key : "{$key}={$previous}");
            $_ENV[$key] = $_SERVER[$key] = $previous === false ? null : $previous;
        }
    }
}
