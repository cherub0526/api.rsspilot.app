<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Plans;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Subscription;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hyperf\Database\Model\Events\Saved;
use PHPUnit\Framework\Attributes\DataProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Console\Contracts\Kernel;

/**
 * hidden-plan-link 驗收（T01）：`plans:link` 產生、沿用、更換、撤回專屬連結（S09、S12、S12b、S12c、S12d、S17）。
 *
 * S09 與 S17 的「hidden API 回 404／結帳回 422」屬於 T02、T03 的端點，在那兩張卡的 RED 補；這裡只驗指令與資料。
 * 輸出的網址用 `CLIENT_URL` 組成（plan r3），測試把它設成正式站的網址。
 *
 * @internal
 * @coversNothing
 */
class LinkTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_URL = 'https://rsspilot.app';

    private false|string $previousClientUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMockingConsoleOutput();
        $this->previousClientUrl = getenv('CLIENT_URL');
        $this->setClientUrl(self::CLIENT_URL . '/');
    }

    protected function tearDown(): void
    {
        putenv($this->previousClientUrl === false ? 'CLIENT_URL' : 'CLIENT_URL=' . $this->previousClientUrl);
        unset($_ENV['CLIENT_URL'], $_SERVER['CLIENT_URL']);

        parent::tearDown();
    }

    private function setClientUrl(?string $url): void
    {
        if ($url === null) {
            putenv('CLIENT_URL');
            unset($_ENV['CLIENT_URL'], $_SERVER['CLIENT_URL']);

            return;
        }
        putenv("CLIENT_URL={$url}");
        $_ENV['CLIENT_URL'] = $_SERVER['CLIENT_URL'] = $url;
    }

    private function plan(string $title, array $attributes = []): Plan
    {
        return Plan::withoutEvents(fn () => Plan::factory()->create(array_merge([
            'title'  => $title,
            'status' => Plan::STATUS_ACTIVE,
        ], $attributes)));
    }

    /** @return array{int, string} */
    private function link(array $parameters): array
    {
        $kernel = $this->app->get(Kernel::class);
        $code = $kernel->call('plans:link', $parameters + ['--no-interaction' => true]);

        return [$code, $kernel->output()];
    }

    /** @return list<array<string, mixed>> */
    private function plansSnapshot(): array
    {
        return DB::table('plans')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function linkFor(string $code): string
    {
        return self::CLIENT_URL . '/pricing?plan=' . $code;
    }

    #[DataProvider('planIdentifiers')]
    public function testS12LinkHidesThePlanGeneratesACodeAndPrintsTheLink(string $by): void
    {
        $partner = $this->plan('Partner', ['visibility' => Plan::VISIBILITY_PUBLIC]);
        $this->plan('Pro');

        [$exit, $output] = $this->link(['plan' => $by === 'id' ? $partner->id : 'Partner', '--force' => true]);

        $partner->refresh();
        $this->assertSame(0, $exit);
        $this->assertSame(Plan::VISIBILITY_HIDDEN, $partner->visibility);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', (string) $partner->access_code);
        $this->assertStringContainsString($this->linkFor($partner->access_code), $output);
    }

    // ── S12 ───────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function planIdentifiers(): array
    {
        return ['title' => ['title'], 'id' => ['id']];
    }

    public function testS12RunningLinkAgainKeepsTheSameCodeAndLink(): void
    {
        $partner = $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);

        [, $first] = $this->link(['plan' => 'Partner']);
        $code = $partner->refresh()->access_code;
        [$exit, $second] = $this->link(['plan' => 'Partner']);

        $this->assertSame(0, $exit);
        $this->assertSame($code, $partner->refresh()->access_code);
        $this->assertStringContainsString($this->linkFor($code), $first);
        $this->assertStringContainsString($this->linkFor($code), $second);
    }

    /** qa 邊界：只改指定的方案，其他方案的 visibility 與 code 都不動。 */
    public function testLinkTouchesOnlyTheNamedPlan(): void
    {
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $this->plan('Pro', ['visibility' => Plan::VISIBILITY_PUBLIC]);
        $before = collect($this->plansSnapshot())->firstWhere('title', 'Pro');

        $this->link(['plan' => 'Partner']);

        $this->assertSame($before, collect($this->plansSnapshot())->firstWhere('title', 'Pro'));
    }

    /** qa 邊界（plan r3）：CLIENT_URL 沒設定時指令失敗，不產生碼。 */
    public function testLinkFailsWithoutAClientUrlAndWritesNothing(): void
    {
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $this->setClientUrl(null);
        $before = $this->plansSnapshot();

        [$exit] = $this->link(['plan' => 'Partner']);

        $this->assertNotSame(0, $exit);
        $this->assertSame($before, $this->plansSnapshot());
    }

    // ── S12b ──────────────────────────────────────────

    public function testS12bAnUnknownTitleFailsAndWritesNothing(): void
    {
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $before = $this->plansSnapshot();

        [$exit] = $this->link(['plan' => 'Nope']);

        $this->assertNotSame(0, $exit);
        $this->assertSame($before, $this->plansSnapshot());
    }

    public function testS12bADuplicateTitleFailsAndWritesNothing(): void
    {
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $before = $this->plansSnapshot();

        [$exit] = $this->link(['plan' => 'Partner']);

        $this->assertNotSame(0, $exit);
        $this->assertSame($before, $this->plansSnapshot());
    }

    // ── S12d ──────────────────────────────────────────

    public function testS12dLinkingAPublicPlanWithoutForceFailsAndShowsTheActiveSubscriberCount(): void
    {
        $pro = $this->plan('Pro', ['visibility' => Plan::VISIBILITY_PUBLIC]);
        $price = Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $pro->id, 'unit' => Price::UNIT_MONTHLY, 'price' => 9.99]));
        // 有效 = Subscription::active()：active，加上 next_date 為 null 或還沒到的 trial（和權益判斷同一套）。
        // paying 是結帳還沒完成，不算。
        $subscriptions = [
            [Subscription::STATUS_ACTIVE, now()->addMonth()],
            [Subscription::STATUS_TRIAL, now()->addDays(3)],
            [Subscription::STATUS_TRIAL, now()->subDay()],
            [Subscription::STATUS_PAYING, now()->addMonth()],
            [Subscription::STATUS_CANCELED, now()->addMonth()],
        ];
        foreach ($subscriptions as [$status, $nextDate]) {
            Subscription::factory()->create([
                'user_id'        => User::factory()->create()->id,
                'plan_id'        => $pro->id,
                'price_id'       => $price->id,
                'payment_method' => Subscription::PAYMENT_METHOD_CLINK,
                'status'         => $status,
                'next_date'      => $nextDate,
            ]);
        }
        $before = $this->plansSnapshot();

        [$exit, $output] = $this->link(['plan' => 'Pro']);

        $this->assertNotSame(0, $exit);
        $this->assertSame($before, $this->plansSnapshot());
        $this->assertStringContainsString('這個方案會從定價頁消失', $output);
        $this->assertMatchesRegularExpression('/\b2\b/', $output, 'active + unexpired trial = 2; expired trial, paying and canceled are not counted');
    }

    public function testS12dLinkingAPublicPlanWithForceHidesItAndPrintsTheLink(): void
    {
        $pro = $this->plan('Pro', ['visibility' => Plan::VISIBILITY_PUBLIC]);

        [$exit, $output] = $this->link(['plan' => 'Pro', '--force' => true]);

        $pro->refresh();
        $this->assertSame(0, $exit);
        $this->assertSame(Plan::VISIBILITY_HIDDEN, $pro->visibility);
        $this->assertStringContainsString($this->linkFor((string) $pro->access_code), $output);
    }

    // ── S12c ──────────────────────────────────────────

    public function testS12cLinkingAnInactivePlanPrintsTheLinkWithAWarning(): void
    {
        $partner = $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN, 'status' => Plan::STATUS_INACTIVE]);

        [$exit, $output] = $this->link(['plan' => 'Partner']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString($this->linkFor((string) $partner->refresh()->access_code), $output);
        $this->assertStringContainsString('方案目前是 inactive，連結不會生效', $output);
        $this->assertSame(Plan::STATUS_INACTIVE, $partner->status, 'linking does not reactivate the plan');
    }

    // ── S09 ───────────────────────────────────────────

    public function testS09RotateReplacesTheCodeAndPrintsTheNewLink(): void
    {
        $partner = $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $this->link(['plan' => 'Partner']);
        $old = $partner->refresh()->access_code;

        [$exit, $output] = $this->link(['plan' => 'Partner', '--rotate' => true]);

        $new = $partner->refresh()->access_code;
        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', (string) $new);
        $this->assertNotSame($old, $new);
        $this->assertStringContainsString($this->linkFor($new), $output);
        $this->assertStringNotContainsString($old, $output);
    }

    // ── S17 ───────────────────────────────────────────

    public function testS17PublicMakesThePlanPublicAndClearsTheCode(): void
    {
        $partner = $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $this->link(['plan' => 'Partner']);
        $this->assertNotNull($partner->refresh()->access_code);

        [$exit] = $this->link(['plan' => 'Partner', '--public' => true]);

        $partner->refresh();
        $this->assertSame(0, $exit);
        $this->assertSame(Plan::VISIBILITY_PUBLIC, $partner->visibility);
        $this->assertNull($partner->access_code);
    }

    /** qa 邊界：--public 與 --rotate 同時給，意思互相矛盾，指令失敗且不動資料。 */
    public function testPublicAndRotateTogetherFailAndWriteNothing(): void
    {
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN, 'access_code' => str_repeat('K', 32)]);
        $before = $this->plansSnapshot();

        [$exit] = $this->link(['plan' => 'Partner', '--public' => true, '--rotate' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertSame($before, $this->plansSnapshot());
    }

    // ── qa r1 邊界 ────────────────────────────────────

    /** SR-1：連結碼不會跟著方案序列化出去，公開的 `GET /v1/plans` 回應裡也沒有。 */
    public function testTheAccessCodeNeverLeavesThroughSerializationOrThePlansApi(): void
    {
        $partner = $this->plan('Partner', ['visibility' => Plan::VISIBILITY_PUBLIC]);
        Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $partner->id, 'unit' => Price::UNIT_MONTHLY, 'price' => 9.99]));
        $this->link(['plan' => 'Partner', '--force' => true]);
        $code = (string) $partner->refresh()->access_code;
        $this->assertSame(32, strlen($code));

        $this->assertArrayNotHasKey('access_code', $partner->toArray());
        $this->assertStringNotContainsString($code, $partner->toJson());
        $this->assertStringNotContainsString($code, (string) $this->json('GET', route('api.v1.plans.index'))->assertStatus(200)->getContent());
    }

    /** `--rotate` 不能繞過 S12d：公開方案沒加 `--force` 一樣失敗、不動資料。 */
    public function testRotateOnAPublicPlanWithoutForceFailsAndWritesNothing(): void
    {
        $this->plan('Pro', ['visibility' => Plan::VISIBILITY_PUBLIC]);
        $before = $this->plansSnapshot();

        [$exit, $output] = $this->link(['plan' => 'Pro', '--rotate' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertSame($before, $this->plansSnapshot());
        $this->assertStringNotContainsString('/pricing?plan=', $output);
    }

    /** 已經是公開方案時 `--public` 什麼都不改，包括 updated_at。 */
    public function testPublicOnAnAlreadyPublicPlanChangesNothing(): void
    {
        $this->plan('Pro', ['visibility' => Plan::VISIBILITY_PUBLIC]);
        $before = $this->plansSnapshot();

        [$exit] = $this->link(['plan' => 'Pro', '--public' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($before, $this->plansSnapshot());
    }

    /** 重複執行（沒有 --rotate）連 updated_at 都不變。 */
    public function testRunningLinkAgainDoesNotTouchTheRow(): void
    {
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $this->link(['plan' => 'Partner']);
        $before = $this->plansSnapshot();

        $this->link(['plan' => 'Partner']);

        $this->assertSame($before, $this->plansSnapshot());
    }

    /**
     * 指令不觸發 Plan 的 model 事件：StripePlanObserver::updated 會把方案同步到 Stripe，
     * 這幾個指令只改 visibility、access_code，不該多打一次 Stripe API。
     */
    public function testLinkCommandsDoNotFireModelEventsThatSyncToStripe(): void
    {
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_PUBLIC]);
        $fired = 0;
        Event::listen(Saved::class, function (Saved $event) use (&$fired): void {
            if ($event->getModel() instanceof Plan) {
                ++$fired;
            }
        });

        $this->link(['plan' => 'Partner', '--force' => true]);
        $this->link(['plan' => 'Partner', '--rotate' => true]);
        $this->link(['plan' => 'Partner', '--public' => true]);

        $this->assertSame(0, $fired);

        // 對照：一般的 save() 會被這個監聽器看到，所以上面的 0 不是監聽器沒接上
        $plan = Plan::query()->where('title', 'Partner')->firstOrFail();
        $plan->sort = $plan->sort + 1;
        $plan->save();
        $this->assertSame(1, $fired);
    }

    /** 兩次 --rotate 得到兩組不同的碼，而且都不等於最初的碼。 */
    public function testEachRotateIssuesAFreshCode(): void
    {
        $this->plan('Partner', ['visibility' => Plan::VISIBILITY_HIDDEN]);
        $codes = [];
        foreach ([[], ['--rotate' => true], ['--rotate' => true]] as $options) {
            $this->link(['plan' => 'Partner'] + $options);
            $codes[] = Plan::query()->where('title', 'Partner')->value('access_code');
        }

        $this->assertCount(3, array_unique($codes));
    }
}
