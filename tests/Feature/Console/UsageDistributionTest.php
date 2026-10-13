<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\ChatUsage;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Subscription;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Console\Contracts\Kernel;

/**
 * usage:distribution —— 成本模型的三個輸入參數要量得出來，而且分母要誠實。
 *
 * 斷言走 `--json` 再 decode，不用 expectsOutputToContain()：整份 JSON 是一次
 * `line()` 寫出去的，而那個 helper 的 Mockery 期望一個寫入只會消掉一條，同一次
 * 寫入裡的第二個子字串永遠不會被滿足（測試會紅在一個其實存在的數字上）。
 *
 * @internal
 * @coversNothing
 */
class UsageDistributionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 貼上限的比例要按「使用者現在的方案」的上限算。
     *
     * 這是整支指令存在的理由：平均值看起來永遠很安全，要看的是有幾成的活躍日是
     * 貼著上限用的。
     */
    public function testDailyQuestionsMeasureTheTailAgainstEachPlanLimit(): void
    {
        $heavy = $this->userOnPlan('Advance', 50);
        $light = $this->userOnPlan('Pro', 20);

        // 重度使用者：兩天貼上限、一天只用一半。
        $this->usage($heavy, '2026-10-01', 50);
        $this->usage($heavy, '2026-10-02', 50);
        $this->usage($heavy, '2026-10-03', 25);
        // 輕度使用者：永遠碰不到上限。
        $this->usage($light, '2026-10-01', 2);

        $plans = $this->report()['daily_questions']['by_plan'];
        /** @var array<string, mixed> $advance */
        $advance = $this->planRow($plans, 'Advance');
        /** @var array<string, mixed> $pro */
        $pro = $this->planRow($plans, 'Pro');

        $this->assertSame(50, $advance['limit']);
        $this->assertSame(3, $advance['active_days']);
        // 3 個活躍日裡有 2 天貼上限。
        $this->assertSame(66.7, $advance['at_limit_days_pct']);
        $this->assertEquals(100, $advance['users_ever_at_limit_pct']);
        // (50 + 50 + 25) / 3 / 50 = 83.3%
        $this->assertSame(83.3, $advance['mean_limit_used_pct']);
        $this->assertSame(50, $advance['p90']);

        $this->assertSame(1, $pro['active_days']);
        $this->assertEquals(0, $pro['at_limit_days_pct']);
        $this->assertEquals(10, $pro['mean_limit_used_pct']);
    }

    /**
     * 分段數是「一個人在一個額度日裡開了幾段對話」，不是對話總數。
     *
     * 超出歷史視窗的比例要一起量：視窗（20 則 = 10 輪）以內的提問成本是線性累積，
     * 超過才封頂，所以「有多少提問落在視窗外」決定了那條曲線的形狀。
     */
    public function testSegmentsCountSessionsPerUserDayAndQuestionsBeyondTheWindow(): void
    {
        $user = $this->userOnPlan('Advance', 50);

        // 同一天分三段：一段長到超過視窗（12 題），兩段各 1 題。
        $this->questions($user, 12);
        $this->questions($user, 1);
        $this->questions($user, 1);

        $segments = $this->report()['segments'];

        $this->assertSame(3, $segments['sessions']);
        $this->assertSame(14, $segments['questions']);
        $this->assertSame(1, $segments['active_user_days']);
        // 三段都在同一天，所以分段數是 3 而不是 1。
        $this->assertEquals(3, $segments['segments_per_user_day']['mean']);
        $this->assertSame(12, $segments['questions_per_session']['max']);
        $this->assertSame(10, $segments['history_window']['rounds']);
        // 1 段（共 3 段）超過視窗，14 題裡有 2 題落在第 10 題之後。
        $this->assertSame(33.3, $segments['history_window']['sessions_pct']);
        $this->assertSame(14.3, $segments['history_window']['questions_pct']);
    }

    /**
     * 搜尋觸發率是數 tool_call 片段，而且分母只算「有片段可看」的回答。
     *
     * `parts` 是 2026-09-01 才加的欄位、舊資料刻意不回填，把那些 null 混進分母會把
     * 觸發率稀釋成一個看起來很安全的數字。
     */
    public function testWebSearchRateCountsToolCallPartsAndExcludesAnswersWithoutParts(): void
    {
        $session = ChatSession::factory()->create();

        $this->answer($session, [
            ['type' => 'tool_call', 'id' => 'c1', 'name' => 'web_search', 'input' => []],
            ['type' => 'tool_call', 'id' => 'c2', 'name' => 'web_search', 'input' => []],
            ['type' => 'text', 'text' => '查到了'],
        ]);
        $this->answer($session, [
            ['type' => 'tool_call', 'id' => 'c3', 'name' => 'web_search', 'input' => []],
            ['type' => 'text', 'text' => '查到了'],
        ]);
        $this->answer($session, [['type' => 'text', 'text' => '不必查']]);
        // parts 為 null：舊資料，應該落在覆蓋率之外而不是被當成「沒搜」。
        $this->answer($session, null);

        $search = $this->report()['web_search'];

        $this->assertSame(4, $search['ai_answers']);
        $this->assertSame(3, $search['with_parts']);
        $this->assertEquals(75, $search['coverage_pct']);
        // 3 則有片段的回答裡有 2 則搜了。
        $this->assertSame(66.7, $search['answers_with_tool_call_pct']);
        $this->assertSame(3, $search['calls']);
        $this->assertEquals(1, $search['calls_per_answer']);
        $this->assertSame(1.5, $search['calls_per_searching_answer']);
        $this->assertSame(['answers' => 2, 'calls' => 3], $search['by_tool']['web_search']);
        // 沒搜的那一則要落在 0 次那一桶，不是被忽略。
        $this->assertSame(1, $search['histogram']['0']);
    }

    /** 期間之外的資料不能被算進去，不然「最近 30 天」會變成「有史以來」。 */
    public function testDaysOptionWindowsTheData(): void
    {
        $user = $this->userOnPlan('Pro', 20);

        $this->usage($user, now()->toDateString(), 7);
        $this->usage($user, now()->subDays(90)->toDateString(), 19);

        $report = $this->report(['--days' => 30, '--json' => true])['daily_questions'];

        $this->assertSame(1, $report['active_days']);
        $this->assertSame(7, $report['questions']);
    }

    /** 人看的那份排版也要真的印得出來，而且帶著讀數限制的提醒。 */
    public function testHumanReadableOutputCarriesTheCaveat(): void
    {
        $user = $this->userOnPlan('Pro', 20);
        $this->usage($user, now()->toDateString(), 20);

        $output = $this->runReport(['--days' => 0]);

        $this->assertStringContainsString('1. 每日提問數分布', $output);
        $this->assertStringContainsString('2. 對話分段分布', $output);
        $this->assertStringContainsString('3. web search 觸發率', $output);
        $this->assertStringContainsString('對照 docs/pricing-cost-model.md 的情境參數', $output);
        $this->assertStringContainsString('升降級過的帳號會被歸到現在的方案', $output);
    }

    /**
     * 跑指令並把 JSON 報表 decode 回陣列。
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function report(array $parameters = ['--days' => 0, '--json' => true]): array
    {
        /** @var array<string, mixed> $decoded */
        return json_decode($this->runReport($parameters), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runReport(array $parameters): string
    {
        $this->withoutMockingConsoleOutput();
        $this->artisan('usage:distribution', $parameters);

        return $this->app->get(Kernel::class)->output();
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     * @return array<string, mixed>
     */
    private function planRow(array $plans, string $title): array
    {
        foreach ($plans as $plan) {
            if ($plan['plan'] === $title) {
                return $plan;
            }
        }

        $this->fail("報表裡沒有 {$title} 這一列。");
    }

    /**
     * 一個訂著指定上限方案的使用者。
     *
     * 方案與價格都要 withoutEvents：Plan / Price 的 observer 會去同步金流商的
     * product，測試裡不該發生任何對外請求。
     */
    private function userOnPlan(string $title, int $chatLimit): User
    {
        /** @var User $user */
        $user = User::factory()->create();

        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'      => $title,
            'chat_limit' => $chatLimit,
        ]));
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 9.99,
        ]));

        Subscription::factory()->create([
            'user_id'    => $user->id,
            'plan_id'    => $plan->id,
            'price_id'   => $price->id,
            'status'     => Subscription::STATUS_ACTIVE,
            'start_date' => now()->subDay(),
        ]);

        return $user;
    }

    private function usage(User $user, string $date, int $count): void
    {
        ChatUsage::query()->create([
            'user_id'    => $user->id,
            'quota_date' => $date,
            'count'      => $count,
        ]);
    }

    /** 一段對話裡問 $count 題。 */
    private function questions(User $user, int $count): void
    {
        $session = ChatSession::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < $count; ++$i) {
            ChatMessage::factory()->create([
                'session_id' => $session->id,
                'role'       => ChatMessage::ROLE_USER,
                // 同一秒內的多則提問靠 created_at 排不出先後，差開才能確定「第 11 題」
                // 真的是第 11 題。
                'created_at' => now()->addSeconds($i),
            ]);
        }
    }

    /** @param null|array<int, array<string, mixed>> $parts */
    private function answer(ChatSession $session, ?array $parts): void
    {
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role'       => ChatMessage::ROLE_AI,
            'parts'      => $parts,
            'created_at' => now(),
        ]);
    }
}
