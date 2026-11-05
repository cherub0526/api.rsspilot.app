<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Hyperf\HttpServer\Router\Handler;
use Hypervel\Router\DispatcherFactory;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Router\Middleware\ThrottleRequests;
use Hypervel\Foundation\Testing\Http\TestResponse;

/**
 * throttle-prefix 驗收（T01）：每一條限流路由都用自己的計數。
 *
 * Hypervel 的 ThrottleRequests 用「prefix + user id（沒登入用 domain|IP）」當 key，不含路由。
 * 沒有 prefix 的 throttle 路由，對同一個使用者共用同一個計數（specs/throttle-prefix/spec.md）。
 *
 * - S01 從 router 取出**所有已註冊的路由**與它們實際生效的 middleware（含 group 層級），
 *   不解析 routes 檔：之後 merge 進來的 mcp、oauth、well-known 路由也會被檢查。
 * - S02、S03 打不存在的資源：throttle 排在 Controller 之前，404 也會計入，所以不需要建立真的 media 或 session。
 *
 * @internal
 * @coversNothing
 */
class ThrottlePrefixTest extends TestCase
{
    use RefreshDatabase;

    /** 路由的 ULID 限制是小寫的 Crockford base32，大寫會在 router 就 404，碰不到 throttle。 */
    private const MEDIA_ID = '01jcxyz0000000000000000000';

    private const SESSION_ID = '01jcxyz0000000000000000001';

    private const SOURCE_ID = '01jcxyz0000000000000000002';

    // ── S01 ───────────────────────────────────────────

    public function testS01EveryThrottledRouteHasItsOwnNonEmptyPrefix(): void
    {
        $throttled = $this->throttledRoutes();

        // 防止空集合造成的假綠：目前至少有 develop 上的 5 條（spec〈既有功能盤點〉）
        $this->assertGreaterThanOrEqual(5, count($throttled), 'the scan must find the throttled routes');
        $uris = array_column($throttled, 'uri');
        foreach (['/skill/download', '/follow-ups', '/thumbnails'] as $known) {
            $this->assertNotEmpty(array_filter($uris, fn (string $uri) => str_contains($uri, $known)), "scan sees {$known}");
        }

        $this->assertSame([], self::violations($throttled), 'every throttle has a non-empty prefix that no other route uses');
    }

    /**
     * S01 的最後一句：「新增一條沒有 prefix 或 prefix 重複的 throttle 路由，這個測試會失敗」。
     * 用假的路由表驗證判斷邏輯本身。
     */
    public function testS01TheScanRejectsAMissingPrefixAndADuplicatePrefix(): void
    {
        $route = fn (string $uri, string $throttle) => ['method' => 'GET', 'uri' => $uri, 'throttle' => $throttle];

        $this->assertSame([], self::violations([
            $route('/a', 'throttle:10,1,a:'),
            $route('/b', 'throttle:10,1,b:'),
        ]));

        $this->assertCount(1, self::violations([$route('/a', 'throttle:10,1')]), 'no prefix');
        $this->assertCount(1, self::violations([$route('/a', 'throttle:10,1,')]), 'empty prefix');
        $this->assertCount(1, self::violations([$route('/a', 'throttle:10')]), 'only max attempts');
        $this->assertCount(1, self::violations([$route('/a', 'throttle:10,1, ')]), 'whitespace prefix');
        $this->assertNotEmpty(self::violations([
            $route('/a', 'throttle:10,1,shared:'),
            $route('/b', 'throttle:30,1,shared:'),
        ]), 'duplicate prefix');
        $this->assertCount(1, self::violations([$route('/a', ThrottleRequests::class . ':10,1')]), 'class-name form without prefix');
    }

    /**
     * qa r1 邊界（spec 非功能需求「prefix 只用固定字串」）：prefix 符合 `plans-hidden:` 的命名格式，
     * 小寫英數加連字號、以冒號結尾。冒號讓 `a:` 和 `ab:` 這種 prefix 不會在 key 上互相重疊。
     */
    public function testEveryPrefixFollowsTheNamingConvention(): void
    {
        foreach ($this->throttledRoutes() as $route) {
            $prefix = trim(explode(',', (string) substr($route['throttle'], (int) strpos($route['throttle'], ':') + 1))[2] ?? '');
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*:$/', $prefix, "{$route['method']} {$route['uri']}");
        }
    }

    /**
     * qa r1 邊界：上限較高的路由用完自己的次數，不會擋到上限較低的路由。
     * 截圖上傳打滿 30 次（第 31 次 429）之後，follow-ups（上限 10）第一次仍然不是 429。
     * 共用計數時這裡一定是 429，所以這條測試能分辨修好與沒修好。
     */
    public function testUsingUpThumbnailUploadsDoesNotBlockFollowUps(): void
    {
        $this->fakeLogin();

        for ($i = 1; $i <= 30; ++$i) {
            $this->thumbnailUpload();
        }
        $this->thumbnailUpload()->assertStatus(429);

        $this->assertNotSame(429, $this->followUps()->getStatusCode());
    }

    // ── S02 ───────────────────────────────────────────

    public function testS02UsingUpSkillDownloadsDoesNotBlockFollowUps(): void
    {
        config(['features.channel_skill' => true]);
        $this->fakeLogin();

        for ($i = 0; $i < 10; ++$i) {
            $this->assertNotSame(429, $this->skillDownload()->getStatusCode(), "download #{$i} is within its own limit");
        }

        $this->assertNotSame(429, $this->followUps()->getStatusCode(), 'follow-ups has only been called once');

        // 對照：download 真的有經過自己的 throttle（不是在 router 或 feature flag 就被擋掉，那樣上面會是假綠）。
        // 再打 20 次湊滿 30 次都不是 429，第 31 次才是 429：follow-ups 那一次也沒有算進 download 的計數。
        for ($i = 10; $i < 30; ++$i) {
            $this->assertNotSame(429, $this->skillDownload()->getStatusCode(), "download #{$i}");
        }
        $this->skillDownload()->assertStatus(429);
    }

    // ── S03 ───────────────────────────────────────────

    public function testS03ThumbnailUploadsAreStillLimitedToThirtyAMinute(): void
    {
        $this->fakeLogin();

        for ($i = 1; $i <= 30; ++$i) {
            $this->assertNotSame(429, $this->thumbnailUpload()->getStatusCode(), "upload #{$i}");
        }
        $this->thumbnailUpload()->assertStatus(429);
    }

    public function testS03FollowUpsAreStillLimitedToTenAMinute(): void
    {
        $this->fakeLogin();

        for ($i = 1; $i <= 10; ++$i) {
            $this->assertNotSame(429, $this->followUps()->getStatusCode(), "follow-ups #{$i}");
        }
        $this->followUps()->assertStatus(429);
    }

    // ── helpers ───────────────────────────────────────

    private function skillDownload(): TestResponse
    {
        return $this->json('GET', route('api.v1.sources.skill.download.index', ['sourceId' => self::SOURCE_ID]));
    }

    private function followUps(): TestResponse
    {
        return $this->json('GET', route('api.v1.media.chat.sessions.follow-ups.show', ['mediaId' => self::MEDIA_ID, 'sessionId' => self::SESSION_ID]));
    }

    private function thumbnailUpload(): TestResponse
    {
        return $this->json('POST', route('api.v1.media.thumbnails.store', ['mediaId' => self::MEDIA_ID]));
    }

    /**
     * 所有已註冊路由中，每一個 throttle middleware 一筆。middleware 取 handler 的 `options['middleware']`，
     * 是 router 合併過 group 層級之後的結果（backend 確認；Kernel 沒有全域或 group 的 throttle）。
     *
     * @return list<array{method: string, uri: string, throttle: string}>
     */
    private function throttledRoutes(): array
    {
        $router = $this->app->get(DispatcherFactory::class)->getRouter('http');
        [$static, $variable] = $router->getData();

        $handlers = [];
        foreach ($static as $method => $items) {
            foreach ($items as $handler) {
                $handlers[] = [$method, $handler];
            }
        }
        foreach ($variable as $method => $chunks) {
            foreach ($chunks as $chunk) {
                foreach ($chunk['routeMap'] ?? [] as $routeMap) {
                    $handlers[] = [$method, $routeMap[0]];
                }
            }
        }

        $routes = [];
        foreach ($handlers as [$method, $handler]) {
            $this->assertInstanceOf(Handler::class, $handler);
            foreach ((array) ($handler->options['middleware'] ?? []) as $middleware) {
                $name = is_object($middleware) && property_exists($middleware, 'middleware') ? (string) $middleware->middleware : (is_string($middleware) ? $middleware : '');
                if (self::isThrottle($name)) {
                    $routes[] = ['method' => $method, 'uri' => $handler->route, 'throttle' => $name];
                }
            }
        }

        return $routes;
    }

    private static function isThrottle(string $middleware): bool
    {
        $name = explode(':', $middleware, 2)[0];

        return $name === 'throttle' || ltrim($name, '\\') === ThrottleRequests::class;
    }

    /**
     * 回傳違規清單（空陣列 = 通過）。
     *
     * @param list<array{method: string, uri: string, throttle: string}> $routes
     * @return list<string>
     */
    private static function violations(array $routes): array
    {
        $violations = [];
        $owners = [];

        foreach ($routes as $route) {
            $label = "{$route['method']} {$route['uri']} [{$route['throttle']}]";
            // `throttle:max,decay,prefix` 或 `<FQCN>:max,decay,prefix`（FQCN 沒有冒號），參數都在第一個冒號之後
            $parameters = explode(',', (string) substr($route['throttle'], (int) strpos($route['throttle'], ':') + 1));
            $prefix = trim($parameters[2] ?? '');

            if ($prefix === '') {
                $violations[] = "no prefix: {$label}";

                continue;
            }

            // 同一個 uri 的不同 method（例如 GET / HEAD）可以共用；不同 uri 不行
            $owners[$prefix][$route['uri']] = $label;
        }

        foreach ($owners as $prefix => $uris) {
            if (count($uris) > 1) {
                $violations[] = "prefix {$prefix} shared by: " . implode(' | ', $uris);
            }
        }

        return $violations;
    }
}
