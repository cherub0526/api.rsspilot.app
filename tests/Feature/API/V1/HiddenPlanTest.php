<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\Price;
use Hypervel\Support\Facades\Event;
use Hypervel\Log\Events\MessageLogged;
use PHPUnit\Framework\Attributes\DataProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\Http\TestResponse;

/**
 * hidden-plan-link 驗收（T02）：`GET /v1/plans/hidden/{code}` 與 `/v1/plans` 不列隱藏方案。
 *
 * 情境：S05、S08（hidden API 的部分）、S14、S15、S16（hidden API 的部分）、S20（hidden API）。
 * Background：公開方案 Free、Pro、Advance；active 的隱藏方案 Partner（K7f…）與 Early（E9x…）。
 *
 * route name 依 routes.md 推導：`GET /v1/plans/hidden/{code}` → `api.v1.plans.hidden.show`。
 *
 * @internal
 * @coversNothing
 */
class HiddenPlanTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER_CODE = 'K7fQ2mZp9xLr4TbW8nVc3HsY6dJa1GeU';

    private const EARLY_CODE = 'E9xR5tKw2pLm7QzN4bVh8CsD3fYa6JgT';

    private Plan $partner;

    private Plan $early;

    /** PlanFactory 的 sort 是隨機值，/v1/plans 依 sort 排序：依建立順序給固定的 sort，Free、Pro、Advance 才有固定順序。 */
    private int $nextSort = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Free' => 0, 'Pro' => 12.99, 'Advance' => 24.99] as $title => $price) {
            $this->plan($title, Plan::VISIBILITY_PUBLIC, null, $price);
        }
        $this->partner = $this->plan('Partner', Plan::VISIBILITY_HIDDEN, self::PARTNER_CODE, 9.99);
        $this->early = $this->plan('Early', Plan::VISIBILITY_HIDDEN, self::EARLY_CODE, 4.99);
    }

    private function plan(string $title, string $visibility, ?string $code, ?float $monthly, string $status = Plan::STATUS_ACTIVE): Plan
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['title' => $title, 'status' => $status, 'sort' => ++$this->nextSort]));
        // 用 query 直接寫：測試不依賴 fillable（T02 紀錄：不能用 request 的輸入 fill Plan）
        Plan::query()->whereKey($plan->id)->update(['visibility' => $visibility, 'access_code' => $code]);
        if ($monthly !== null) {
            Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $plan->id, 'unit' => Price::UNIT_MONTHLY, 'price' => $monthly]));
        }

        return $plan->refresh();
    }

    private function hidden(string $code): TestResponse
    {
        return $this->json('GET', route('api.v1.plans.hidden.show', ['code' => $code]));
    }

    /**
     * 單筆 resource 依 resources.md **不包 data**（plan r7 定案，hidden-plan-link T04 qa r3 High-1）。
     * 只接受這一種：前端照這個格式解析，兩種都接受的話，格式一變就會在整合時才發現。
     */
    private function payload(TestResponse $response): array
    {
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayNotHasKey('data', $json, 'the plan is returned unwrapped, not under data');
        $this->assertArrayHasKey('id', $json);

        return $json;
    }

    /** 比對用：去掉每次都會變的 header（Date、限流剩餘次數）。 */
    private function comparable(TestResponse $response): array
    {
        $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);
        unset($headers['date'], $headers['x-ratelimit-remaining'], $headers['x-ratelimit-reset'], $headers['retry-after']);
        ksort($headers);

        return [$response->getStatusCode(), (string) $response->getContent(), $headers];
    }

    // ── 200 ───────────────────────────────────────────

    public function testAValidCodeReturnsTheHiddenPlanWithItsPricesAndHiddenTrue(): void
    {
        $response = $this->hidden(self::PARTNER_CODE)->assertStatus(200);

        $plan = $this->payload($response);
        $this->assertSame($this->partner->id, $plan['id']);
        $this->assertSame('Partner', $plan['title']);
        $this->assertTrue($plan['hidden']);
        $this->assertCount(1, $plan['prices']);
        $this->assertSame(Price::UNIT_MONTHLY, $plan['prices'][0]['unit']);
        // 和 /v1/plans 的項目同一組欄位，另外多 hidden
        $this->assertArrayHasKey('channel_limit', $plan);
        $this->assertArrayNotHasKey('access_code', $plan);
        $this->assertStringNotContainsString(self::PARTNER_CODE, (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) ($response->getHeader('Cache-Control')[0] ?? ''));
    }

    public function testEachCodeOnlyOpensItsOwnPlan(): void
    {
        $this->assertSame($this->early->id, $this->payload($this->hidden(self::EARLY_CODE)->assertStatus(200))['id']);
    }

    // ── S05 ───────────────────────────────────────────

    public function testS05ThePublicListHasNoHiddenPlansAndNoCodes(): void
    {
        $response = $this->json('GET', route('api.v1.plans.index'))->assertStatus(200);

        $items = $response->json('data');
        $this->assertSame(['Free', 'Pro', 'Advance'], array_values(array_intersect(array_column($items, 'title'), ['Free', 'Pro', 'Advance', 'Partner', 'Early'])));
        $this->assertNotContains('Partner', array_column($items, 'title'));
        $this->assertNotContains('Early', array_column($items, 'title'));
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(self::PARTNER_CODE, $body);
        $this->assertStringNotContainsString(self::EARLY_CODE, $body);
        $this->assertStringNotContainsString('access_code', $body);
        foreach ($items as $item) {
            $this->assertFalse($item['hidden'], "{$item['title']} is public");
        }
    }

    // ── S14：四種 404 完全相同 ────────────────────────

    public function testS14TheFourNotFoundCasesAreIndistinguishable(): void
    {
        $public = $this->plan('Legacy', Plan::VISIBILITY_PUBLIC, 'LeftoverCode0000000000000000000A', 9.99);
        $inactive = $this->plan('Closed', Plan::VISIBILITY_HIDDEN, 'ClosedCode000000000000000000000B', 9.99, Plan::STATUS_INACTIVE);
        $noPrice = $this->plan('NoPrice', Plan::VISIBILITY_HIDDEN, 'NoPriceCode00000000000000000000C', null);
        $this->assertNotNull($public->access_code);

        $responses = [
            'unknown code'      => $this->hidden('UnknownCode0000000000000000000ZZ'),
            'public plan'       => $this->hidden('LeftoverCode0000000000000000000A'),
            'inactive plan'     => $this->hidden($inactive->access_code),
            'plan has no price' => $this->hidden($noPrice->access_code),
        ];

        $first = null;
        foreach ($responses as $case => $response) {
            $response->assertStatus(404);
            $this->assertStringContainsString('no-store', (string) ($response->getHeader('Cache-Control')[0] ?? ''), $case);
            $first ??= $this->comparable($response);
            $this->assertSame($first, $this->comparable($response), "{$case} looks the same as the others");
        }
    }

    // ── S08（hidden API 的部分） ──────────────────────

    public function testS08DeactivatingThePlanMakesItsLinkNotFound(): void
    {
        $this->hidden(self::PARTNER_CODE)->assertStatus(200);
        Plan::query()->whereKey($this->partner->id)->update(['status' => Plan::STATUS_INACTIVE]);

        $this->hidden(self::PARTNER_CODE)->assertStatus(404);
    }

    /**
     * 網址直接組：`route()` 會把 `%2F` 再編碼一次，測不到原始的 `..%2F..`。
     * RED 時路由還不存在，所以這組本來就是 404；它守的是實作之後「不會變成 500、不會命中方案」。
     */
    #[DataProvider('malformedCodes')]
    public function testS15AMalformedCodeIsNotFoundNotAServerError(string $code): void
    {
        $response = $this->json('GET', route('api.v1.plans.index') . '/hidden/' . $code);

        $response->assertStatus(404);
        $this->assertStringNotContainsString('Partner', (string) $response->getContent());
    }

    // ── S15：格式不正確 ───────────────────────────────

    /** @return array<string, array{string}> */
    public static function malformedCodes(): array
    {
        return [
            'too short'        => [substr(self::PARTNER_CODE, 0, 31)],
            'too long'         => [self::PARTNER_CODE . 'A'],
            'path traversal'   => ['..%2F..'],
            'nul byte'         => ['abc%00'],
            'non-alphanumeric' => [substr(self::PARTNER_CODE, 0, 31) . '-'],
            'sql-ish'          => ["'%20OR%201=1%20--"],
        ];
    }

    /** 大小寫不同就是另一組碼，不做不分大小寫的比對。 */
    public function testTheCodeIsCaseSensitive(): void
    {
        $this->hidden(strtolower(self::PARTNER_CODE))->assertStatus(404);
    }

    // ── S16（hidden API 的部分） ──────────────────────

    public function testS16TheHiddenApiNeverLogsTheCode(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message . ' ' . json_encode($event->context);
        });

        $this->hidden(self::PARTNER_CODE)->assertStatus(200);
        $this->hidden('UnknownCode0000000000000000000ZZ')->assertStatus(404);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::PARTNER_CODE, $line);
            $this->assertStringNotContainsString('UnknownCode0000000000000000000ZZ', $line);
        }
    }

    // ── S20（hidden API） ─────────────────────────────

    public function testS20TheHiddenApiIsThrottledAtTwentyRequestsAMinutePerIp(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->hidden($i % 2 === 0 ? self::PARTNER_CODE : 'UnknownCode0000000000000000000ZZ')->assertStatus($i % 2 === 0 ? 200 : 404);
        }

        // 猜碼也算進同一個計數：第 21 次不論對錯都是 429
        $this->hidden(self::PARTNER_CODE)->assertStatus(429);
    }

    /** /v1/plans 不受 hidden API 的限流影響（兩個計數各自獨立）。 */
    public function testThePublicListIsNotBlockedByTheHiddenApiThrottle(): void
    {
        for ($i = 0; $i < 21; ++$i) {
            $this->hidden('UnknownCode0000000000000000000ZZ');
        }

        $this->json('GET', route('api.v1.plans.index'))->assertStatus(200);
    }
}
