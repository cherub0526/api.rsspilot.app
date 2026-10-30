<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Clink;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\Clink;
use App\Models\Price;
use App\Models\Subscription;
use Hypervel\Support\Facades\Http;
use Hypervel\HttpClient\Request as HttpRequest;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class SyncTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Price $monthly;

    public function setUp(): void
    {
        parent::setUp();

        $free = Plan::withoutEvents(fn () => Plan::factory()->create(['title' => 'Free']));
        Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $free->id, 'unit' => Price::UNIT_MONTHLY, 'price' => 0]));

        $this->plan = Plan::withoutEvents(fn () => Plan::factory()->create(['title' => 'Pro', 'description' => 'Pro plan']));
        $this->monthly = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => '12.99',
        ]));
    }

    public function testCreatesTheProductAndBothPriceVariants(): void
    {
        $this->fakeCreation();

        $this->artisan('clink:sync')->run();

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/product/image/upload')
            && $request->hasFile('file', null, 'logo.png'));

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/product')
            && $request['name'] === 'Pro'
            && $request['image'] === 'oss_logo'
            && $request['taxCategory'] === 'software_service');

        $prices = Http::recorded(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/price'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values();

        $this->assertCount(2, $prices, '只替付費 price 建，免費方案略過');

        foreach ($prices as $body) {
            $this->assertSame('prd_pro', $body['productId']);
            $this->assertSame('USD', $body['currency']);
            $this->assertSame(12.99, $body['unitAmount'], '主幣別，不是美分');
            $this->assertSame('recurring', $body['priceType']);
            $this->assertSame('month', $body['recurringDetails']['interval']);
            $this->assertSame(1, $body['recurringDetails']['intervalCount']);
            $this->assertSame('flat_rate', $body['recurringDetails']['pricingModel']);
        }

        $this->assertArrayNotHasKey('trialPeriodDays', $prices[0]['recurringDetails'], 'standard 不帶試用');
        $this->assertSame(Subscription::TRIAL_DAYS, $prices[1]['recurringDetails']['trialPeriodDays']);

        $this->assertDatabaseHas('clinks', ['foreign_type' => Plan::class, 'foreign_id' => $this->plan->id, 'clink_id' => 'prd_pro']);
        $this->assertSame(
            [Clink::VARIANT_STANDARD, Clink::VARIANT_TRIAL],
            $this->monthly->clink()->orderBy('variant')->pluck('variant')->all()
        );
    }

    /** 季繳送 quarter，由 Clink 解析，不換算成 3 個月。 */
    public function testQuarterlyAndAnnualIntervals(): void
    {
        $this->monthly->fill(['unit' => Price::UNIT_QUARTERLY])->save();
        Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $this->plan->id, 'unit' => Price::UNIT_ANNUALLY, 'price' => '120']));
        $this->fakeCreation();

        $this->artisan('clink:sync')->run();

        $intervals = Http::recorded(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/price'))
            ->map(fn ($pair) => $pair[0]->data()['recurringDetails']['interval'])
            ->unique()->sort()->values()->all();

        $this->assertSame(['quarter', 'year'], $intervals);
    }

    /** 已有對應且內容一致時不建也不改；重跑幾次結果都一樣。 */
    public function testLeavesMatchingPricesAlone(): void
    {
        $this->seedMappings();
        $this->fakeExisting(fn (string $variant) => $this->priceVo($variant));

        $this->artisan('clink:sync')->run();

        Http::assertNotSent(fn (HttpRequest $request) => in_array($request->method(), ['POST', 'PUT'], true));
    }

    /** Clink 的 price 可以改：金額或試用天數不符就 PUT 修正。 */
    public function testUpdatesAPriceThatDrifted(): void
    {
        $this->seedMappings();
        $this->fakeExisting(fn (string $variant) => $variant === Clink::VARIANT_TRIAL
            ? $this->priceVo($variant, ['unitAmount' => 9.99, 'recurring' => ['interval' => 'month', 'intervalCount' => 1, 'trialPeriodDays' => 30]])
            : $this->priceVo($variant));

        $this->artisan('clink:sync')->expectsOutputToContain('price_pro_monthly_trial')->run();

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/price/price_pro_monthly_trial')
            && $request['unitAmount'] === 12.99
            && $request['recurringDetails']['trialPeriodDays'] === Subscription::TRIAL_DAYS);
        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/price_pro_monthly_standard'));
    }

    /**
     * 稅外加是使用者定案的前提，但 Clink API 沒有任何欄位可以設定或讀回它。
     * 同步不能默默接受，要報失敗。
     */
    public function testFailsLoudlyWhenTaxExclusiveCannotBeConfirmed(): void
    {
        $this->fakeCreation();

        $this->artisan('clink:sync')
            ->expectsOutputToContain('稅外加')
            ->assertExitCode(1);
    }

    // ================================================================

    private function fakeCreation(): void
    {
        $counter = 0;

        Http::fake([
            '*/product/image/upload' => $this->ok(['ossId' => 'oss_logo']),
            '*/api/product'          => $this->ok(['productId' => 'prd_pro', 'taxCategory' => 'software_service']),
            '*/api/price'            => function () use (&$counter) {
                return $this->ok(['priceId' => 'price_new_' . ++$counter]);
            },
        ]);
    }

    private function seedMappings(): void
    {
        Clink::create(['foreign_type' => Plan::class, 'foreign_id' => $this->plan->id, 'clink_id' => 'prd_pro']);

        foreach (Clink::VARIANTS as $variant) {
            Clink::create([
                'foreign_type' => Price::class,
                'foreign_id'   => $this->monthly->id,
                'clink_id'     => "price_pro_monthly_{$variant}",
                'variant'      => $variant,
            ]);
        }
    }

    private function fakeExisting(callable $priceFor): void
    {
        Http::fake(function (HttpRequest $request) use ($priceFor) {
            $url = $request->url();

            return match (true) {
                $request->method() === 'PUT'                       => $this->ok(['priceId' => 'x']),
                str_ends_with($url, '/product/prd_pro')            => $this->ok(['productId' => 'prd_pro', 'taxCategory' => 'software_service', 'active' => true]),
                str_ends_with($url, '/price_pro_monthly_trial')    => $this->ok($priceFor(Clink::VARIANT_TRIAL)),
                str_ends_with($url, '/price_pro_monthly_standard') => $this->ok($priceFor(Clink::VARIANT_STANDARD)),
                default                                            => Http::response(['code' => 404, 'msg' => 'unexpected ' . $url], 404),
            };
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function priceVo(string $variant, array $overrides = []): array
    {
        $recurring = ['interval' => 'month', 'intervalCount' => 1, 'pricingModel' => 'flat_rate'];

        if ($variant === Clink::VARIANT_TRIAL) {
            $recurring['trialPeriodDays'] = Subscription::TRIAL_DAYS;
        }

        return array_merge([
            'priceId'    => "price_pro_monthly_{$variant}",
            'productId'  => 'prd_pro',
            'currency'   => 'USD',
            'unitAmount' => 12.99,
            'priceType'  => 'recurring',
            'recurring'  => $recurring,
            'active'     => true,
        ], $overrides);
    }

    private function ok(array $data)
    {
        return Http::response(['code' => 200, 'msg' => 'success', 'data' => $data]);
    }
}
