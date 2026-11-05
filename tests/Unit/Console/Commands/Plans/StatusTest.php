<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Plans;

use Tests\TestCase;
use App\Models\Plan;
use Hypervel\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Console\Contracts\Kernel;

/**
 * hidden-plan-link（T01）：`plans:status {plan} {active|inactive}`，S08 用它關閉方案。
 * 情境沒有獨立的 Scenario，依 qa r2 審查第 6 點比照 S12／S12b：id 與 title 都能用，找不到、重複或狀態值不對就失敗且不動資料。
 *
 * @internal
 * @coversNothing
 */
class StatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMockingConsoleOutput();
    }

    private function plan(string $title, string $status = Plan::STATUS_ACTIVE): Plan
    {
        return Plan::withoutEvents(fn () => Plan::factory()->create(['title' => $title, 'status' => $status]));
    }

    private function setStatus(array $parameters): int
    {
        return $this->app->get(Kernel::class)->call('plans:status', $parameters + ['--no-interaction' => true]);
    }

    /** @return list<array<string, mixed>> */
    private function plansSnapshot(): array
    {
        return DB::table('plans')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    #[DataProvider('planIdentifiers')]
    public function testStatusDeactivatesAndReactivatesThePlan(string $by): void
    {
        $partner = $this->plan('Partner');
        $other = $this->plan('Pro');
        $ref = $by === 'id' ? $partner->id : 'Partner';

        $this->assertSame(0, $this->setStatus(['plan' => $ref, 'status' => Plan::STATUS_INACTIVE]));
        $this->assertSame(Plan::STATUS_INACTIVE, $partner->refresh()->status);
        $this->assertSame(Plan::STATUS_ACTIVE, $other->refresh()->status, 'only the named plan changes');

        $this->assertSame(0, $this->setStatus(['plan' => $ref, 'status' => Plan::STATUS_ACTIVE]));
        $this->assertSame(Plan::STATUS_ACTIVE, $partner->refresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function planIdentifiers(): array
    {
        return ['title' => ['title'], 'id' => ['id']];
    }

    #[DataProvider('invalidInvocations')]
    public function testStatusFailsAndWritesNothing(string $plan, string $status): void
    {
        $this->plan('Partner');
        $this->plan('Twin');
        $this->plan('Twin');
        $before = $this->plansSnapshot();

        $this->assertNotSame(0, $this->setStatus(['plan' => $plan, 'status' => $status]));
        $this->assertSame($before, $this->plansSnapshot());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidInvocations(): array
    {
        return [
            'unknown title'   => ['Nope', Plan::STATUS_INACTIVE],
            'duplicate title' => ['Twin', Plan::STATUS_INACTIVE],
            'unknown status'  => ['Partner', 'deleted'],
        ];
    }

    /** qa r1 邊界：設成目前已經是的狀態，成功且不動資料（包括 updated_at）。 */
    public function testSettingTheCurrentStatusAgainChangesNothing(): void
    {
        $this->plan('Partner', Plan::STATUS_INACTIVE);
        $before = $this->plansSnapshot();

        $this->assertSame(0, $this->setStatus(['plan' => 'Partner', 'status' => Plan::STATUS_INACTIVE]));
        $this->assertSame($before, $this->plansSnapshot());
    }

    /** qa r1 邊界（pm 定案）：改成 inactive 不清除連結碼，改回 active 後原本的連結恢復。 */
    public function testDeactivatingKeepsTheAccessCodeAndVisibility(): void
    {
        $partner = $this->plan('Partner');
        Plan::query()->whereKey($partner->id)->update(['visibility' => Plan::VISIBILITY_HIDDEN, 'access_code' => str_repeat('K', 32)]);

        $this->setStatus(['plan' => 'Partner', 'status' => Plan::STATUS_INACTIVE]);
        $this->setStatus(['plan' => 'Partner', 'status' => Plan::STATUS_ACTIVE]);

        $partner->refresh();
        $this->assertSame(Plan::VISIBILITY_HIDDEN, $partner->visibility);
        $this->assertSame(str_repeat('K', 32), $partner->access_code);
    }

    /** qa r1 邊界：status 大小寫不同（`Inactive`）也算不合法，不猜。 */
    public function testStatusValueIsCaseSensitive(): void
    {
        $this->plan('Partner');
        $before = $this->plansSnapshot();

        $this->assertNotSame(0, $this->setStatus(['plan' => 'Partner', 'status' => 'Inactive']));
        $this->assertSame($before, $this->plansSnapshot());
    }
}
