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
use App\Http\Controllers\API\V1\Webhook\ClinkController;

/**
 * @internal
 * @coversNothing
 */
class VerifyTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://api.example.test';

    private Price $monthly;

    /** @var array<string, array<string, mixed>> */
    private array $prices = [];

    /** @var list<array<string, mixed>> */
    private array $endpoints = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::APP_URL]);

        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['title' => 'Pro']));
        $this->monthly = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => '12.99',
        ]));

        Clink::create(['foreign_type' => Plan::class, 'foreign_id' => $plan->id, 'clink_id' => 'prd_pro']);

        foreach (Clink::VARIANTS as $variant) {
            Clink::create([
                'foreign_type' => Price::class,
                'foreign_id'   => $this->monthly->id,
                'clink_id'     => "price_{$variant}",
                'variant'      => $variant,
            ]);
            $this->prices[$variant] = $this->priceVo($variant);
        }

        $this->endpoints = [[
            'id'      => 'whk_1',
            'url'     => self::APP_URL . '/v1/webhook/clink',
            'enabled' => true,
            'events'  => ClinkController::HANDLED_EVENTS,
        ]];

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/webhook/endpoints')    => Http::response(['code' => 200, 'msg' => 'success', 'total' => count($this->endpoints), 'rows' => $this->endpoints]),
                str_ends_with($url, '/product/prd_pro')      => $this->ok(['productId' => 'prd_pro', 'taxCategory' => 'software_service', 'active' => true]),
                str_ends_with($url, '/price/price_standard') => $this->ok($this->prices[Clink::VARIANT_STANDARD]),
                str_ends_with($url, '/price/price_trial')    => $this->ok($this->prices[Clink::VARIANT_TRIAL]),
                default                                      => Http::response(['code' => 404, 'msg' => 'unexpected'], 404),
            };
        });
    }

    /** 其他都對、但 Clink 沒有回報稅外加：不能默默通過。 */
    public function testFailsWhenTaxExclusiveCannotBeConfirmed(): void
    {
        $this->artisan('clink:verify')
            ->expectsOutputToContain('稅外加')
            ->doesntExpectOutputToContain('全部通過')
            ->assertExitCode(1);
    }

    /** Clink 哪天在 price 上回報稅外加，檢查就照實通過。 */
    public function testPassesWhenEverythingMatchesAndTaxIsExclusive(): void
    {
        foreach (Clink::VARIANTS as $variant) {
            $this->prices[$variant]['taxBehavior'] = 'exclusive';
        }

        $this->artisan('clink:verify')
            ->expectsOutputToContain('全部通過')
            ->assertExitCode(0);
    }

    public function testFailsOnAnInclusiveTaxBehavior(): void
    {
        $this->confirmTax();
        $this->prices[Clink::VARIANT_STANDARD]['taxBehavior'] = 'inclusive';

        $this->artisan('clink:verify')->expectsOutputToContain('inclusive')->assertExitCode(1);
    }

    public function testFailsOnAnAmountMismatch(): void
    {
        $this->confirmTax();
        $this->prices[Clink::VARIANT_STANDARD]['unitAmount'] = 1299;

        $this->artisan('clink:verify')->expectsOutputToContain('金額不符')->assertExitCode(1);
    }

    /** 最危險的方向：沒資格的人會拿到試用。 */
    public function testFailsWhenTheStandardPriceHasATrial(): void
    {
        $this->confirmTax();
        $this->prices[Clink::VARIANT_STANDARD]['recurring']['trialPeriodDays'] = 14;

        $this->artisan('clink:verify')->expectsOutputToContain('standard 變體卻有 14 天試用')->assertExitCode(1);
    }

    public function testFailsWhenTheTrialLengthDiffers(): void
    {
        $this->confirmTax();
        $this->prices[Clink::VARIANT_TRIAL]['recurring']['trialPeriodDays'] = 30;

        $this->artisan('clink:verify')->expectsOutputToContain('試用 30 天')->assertExitCode(1);
    }

    public function testFailsWhenAVariantIsMissing(): void
    {
        $this->confirmTax();
        Clink::query()->where('clink_id', 'price_trial')->delete();

        $this->artisan('clink:verify')->expectsOutputToContain('缺少 trial')->assertExitCode(1);
    }

    public function testFailsWhenTheKeyDoesNotMatchTheMode(): void
    {
        $this->confirmTax();

        $this->withEnv('CLINK_API_KEY', 'sk_prod_live', function () {
            $this->artisan('clink:verify')->expectsOutputToContain('CLINK_TEST_MODE')->assertExitCode(1);
        });
    }

    /** 少訂一個事件不會讓任何東西報錯，只會讓某種狀態變化永遠不進來，所以要主動查。 */
    public function testFailsWhenTheWebhookMissesAnEvent(): void
    {
        $this->confirmTax();
        $this->endpoints[0]['events'] = array_values(array_diff(ClinkController::HANDLED_EVENTS, ['subscription.updated.renewed']));

        $this->artisan('clink:verify')->expectsOutputToContain('subscription.updated.renewed')->assertExitCode(1);
    }

    public function testFailsWhenNoWebhookPointsAtUs(): void
    {
        $this->confirmTax();
        $this->endpoints[0]['url'] = 'https://elsewhere.example/v1/webhook/clink';

        $this->artisan('clink:verify')->expectsOutputToContain('/v1/webhook/clink')->assertExitCode(1);
    }

    // ================================================================

    private function confirmTax(): void
    {
        foreach (Clink::VARIANTS as $variant) {
            $this->prices[$variant]['taxBehavior'] = 'exclusive';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function priceVo(string $variant): array
    {
        $recurring = ['interval' => 'month', 'intervalCount' => 1, 'pricingModel' => 'flat_rate'];

        if ($variant === Clink::VARIANT_TRIAL) {
            $recurring['trialPeriodDays'] = Subscription::TRIAL_DAYS;
        }

        return [
            'priceId'    => "price_{$variant}",
            'productId'  => 'prd_pro',
            'currency'   => 'USD',
            'unitAmount' => 12.99,
            'priceType'  => 'recurring',
            'recurring'  => $recurring,
            'active'     => true,
        ];
    }

    private function ok(array $data)
    {
        return Http::response(['code' => 200, 'msg' => 'success', 'data' => $data]);
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
