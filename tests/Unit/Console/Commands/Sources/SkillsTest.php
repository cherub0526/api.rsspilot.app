<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Sources;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Console\Kernel;
use App\Models\AiModel;
use App\Models\Summary;
use App\Models\SourceSkill;
use App\Models\Subscription;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Console\Scheduling\Schedule;
use App\Jobs\Sources\GenerateSourceSkillJob;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * channel-skill T04：`sources:skills` 排程（S10、S11、S12、S36；plan〈後端變更〉sources:skills）。
 *
 * 介面約定：
 *   php artisan sources:skills [--dry-run] [--estimate]
 *   config `skills.batch_size`（每次最多排入幾組，預設 20）
 *   合格組：頻道（只限 youtube_channel）有 ≥1 位 skill_enabled 且訂閱有效（含試用）的訂閱者、共用摘要 ≥ 3；
 *     語言 = 這些訂閱者的 AI 語言集合（沒有 settings → en；不支援的語言 → en，同 T05 pm 裁定 1）
 *   首次／重試：沒有資料列；failed 且 failed_at ≥ 24 小時前；generating 且 started_at 超過 15 分鐘
 *   更新：now − generated_at ≥ 7 天 且 |目前最新 30 支 − media_ids| ≥ 5
 *   排序：首次／重試 > 更新；同類內合格訂閱者數多到少，再依 source id
 *   排入前 upsert generating（寫 started_at）；dispatch 經 T13 的 dispatchGeneration()
 *   Kernel：每小時、onOneServer、withoutOverlapping
 *
 * pm 裁定（T04 卡〈pm 裁定〉）：
 *   - 排序的合格訂閱者數以 (頻道, 語言) 組計
 *   - `--dry-run` 單獨帶：列出這一輪會排入的組（受 batch_size 限制）與組數；`--estimate` 單獨帶視同兩者都帶；絕不派工、不寫 DB、不動每日計數器
 *   - `--estimate` 輸出：組數（`first: N`、`retry: N`、`update: N`）、預估輸入 token、輸出 token 上限（組數 × 8000）、
 *     預估金額（`$` 開頭；`--model=<provider_model>` 覆寫模型，單價讀 AiModel 的 input_price／output_price，
 *     每百萬 token；查不到單價時金額顯示 `unknown`，exit 0）、含重試上限（×3）的金額
 *   以上輸出標籤是 qa 為了斷言訂的，backend 可以提議改字，我會同步修改測試
 *
 * @internal
 * @coversNothing
 */
class SkillsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::flush();
        Carbon::setTestNow('2026-10-30 12:00:00');
        config(['skills.daily_global_limit' => 1000, 'skills.batch_size' => 20, 'features.channel_skill' => true]);
        $this->pro = $this->plan(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixture ─────────────────────────────────────

    private function plan(bool $skill): Plan
    {
        return Plan::withoutEvents(fn () => Plan::factory()->create(['skill_enabled' => $skill]));
    }

    /**
     * @param null|string $language null = 沒有 settings
     * @param null|Plan $plan null = 免費（沒有訂閱）
     */
    private function subscriber(Source $source, ?string $language, ?Plan $plan, string $status = Subscription::STATUS_ACTIVE, ?Carbon $nextDate = null): User
    {
        $user = User::factory()->create();
        $user->setting()->delete();
        if ($language !== null) {
            $user->setting()->updateOrCreate([], ['data' => ['ai' => ['language' => $language]]]);
        }
        if ($plan !== null) {
            $price = Price::withoutEvents(fn () => Price::factory()->create([
                'plan_id' => $plan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 12.99,
            ]));
            Subscription::factory()->create([
                'user_id'        => $user->id,
                'plan_id'        => $plan->id,
                'price_id'       => $price->id,
                'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
                'status'         => $status,
                'next_date'      => $nextDate ?? now()->addMonth(),
            ]);
        }
        $user->sources()->attach($source->id);

        return $user;
    }

    /** @return list<Media> 由舊到新 */
    private function videos(Source $source, int $count, ?Carbon $from = null): array
    {
        $from ??= Carbon::parse('2026-01-01');
        $media = [];
        for ($n = 0; $n < $count; ++$n) {
            $item = Media::factory()->create(['source_id' => $source->id, 'published_at' => $from->copy()->addHours($n)]);
            $item->summaries()->create([
                'user_id' => null, 'locale' => 'en', 'status' => Summary::STATUS_COMPLETED,
                'text'    => ['short_summary' => 's', 'long_summary' => ['content' => "Body {$n}", 'key_points' => [], 'keywords' => []]],
            ]);
            $media[] = $item;
        }

        return $media;
    }

    private function channel(int $summaries = 3, array $attributes = []): Source
    {
        $source = Source::factory()->create(array_merge(['type' => Source::TYPE_YOUTUBE_CHANNEL, 'status' => Source::STATUS_ACTIVE], $attributes));
        $this->videos($source, $summaries);

        return $source;
    }

    private function row(Source $source, string $language, string $status, array $attributes = []): SourceSkill
    {
        return SourceSkill::factory()->create(array_merge([
            'source_id'    => $source->id,
            'language'     => $language,
            'status'       => $status,
            'content'      => null,
            'references'   => null,
            'generated_at' => null,
        ], $attributes));
    }

    private function runCommand(array $options = []): void
    {
        $this->artisan('sources:skills', $options)->run();
    }

    /** @return list<string> 排入的 "<source id>:<language>"，依派工順序 */
    private function queued(): array
    {
        return Queue::pushed(GenerateSourceSkillJob::class)->map(fn (GenerateSourceSkillJob $job) => $job->uniqueId())->values()->all();
    }

    private function generatingRows(): int
    {
        return SourceSkill::query()->where('status', SourceSkill::STATUS_GENERATING)->count();
    }

    // ── 全站開關（config features.channel_skill）──────────

    /** 關閉時排程照跑，但一組都不排、也不建資料列：排程登記留著，開關打開就恢復。 */
    public function testQueuesNothingWhileTheFeatureIsOff(): void
    {
        config(['features.channel_skill' => false]);
        $source = $this->channel();
        $this->subscriber($source, 'zh-TW', $this->pro);

        $this->artisan('sources:skills')->assertExitCode(0);

        Queue::assertNotPushed(GenerateSourceSkillJob::class);
        $this->assertSame(0, SourceSkill::query()->count());
    }

    // ── S10：合格組與語言集合 ───────────────────────────

    public function testS10QueuesEveryProSubscribersLanguageButNotFreeSubscribers(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'zh-TW', $this->pro);
        $this->subscriber($source, 'en', $this->pro);
        $this->subscriber($source, 'ko', null);

        $this->runCommand();

        $queued = $this->queued();
        sort($queued);
        $expected = ["{$source->id}:en", "{$source->id}:zh-TW"];
        sort($expected);
        $this->assertSame($expected, $queued);

        foreach (['zh-TW', 'en'] as $language) {
            $row = SourceSkill::query()->where('source_id', $source->id)->where('language', $language)->firstOrFail();
            $this->assertSame(SourceSkill::STATUS_GENERATING, $row->status, 'upserted before dispatch');
            $this->assertTrue($row->started_at?->equalTo(now()));
        }
        $this->assertSame(0, SourceSkill::query()->where('language', 'ko')->count());
    }

    public function testTwoSubscribersWithTheSameLanguageMakeOneGroup(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'ja', $this->pro);
        $this->subscriber($source, 'ja', $this->pro);

        $this->runCommand();

        $this->assertSame(["{$source->id}:ja"], $this->queued());
    }

    public function testFewerThanThreeSharedSummariesIsNotEligible(): void
    {
        $source = $this->channel(2);
        $this->subscriber($source, 'en', $this->pro);

        $this->runCommand();

        $this->assertSame([], $this->queued());
        $this->assertSame(0, SourceSkill::query()->count());
    }

    public function testPlaylistsAreNeverQueued(): void
    {
        $source = $this->channel(5, ['type' => Source::TYPE_YOUTUBE_PLAYLIST]);
        $this->subscriber($source, 'en', $this->pro);

        $this->runCommand();

        $this->assertSame([], $this->queued());
    }

    // ── S36：排除條件 ───────────────────────────────

    public function testS36AnExpiredProSubscriberIsNotEligible(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'en', $this->pro, Subscription::STATUS_CANCELED, now()->subDay());

        $this->runCommand();

        $this->assertSame([], $this->queued());
    }

    public function testS36AnotherPlanWithSkillIsEligible(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'en', $this->plan(true));

        $this->runCommand();

        $this->assertSame(["{$source->id}:en"], $this->queued());
    }

    public function testS36ATrialProSubscriberIsEligible(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'ja', $this->pro, Subscription::STATUS_TRIAL, now()->addDays(7));

        $this->runCommand();

        $this->assertSame(["{$source->id}:ja"], $this->queued());
    }

    public function testS36AProSubscriberWithoutSettingsUsesTheDefaultLanguage(): void
    {
        $source = $this->channel();
        $this->subscriber($source, null, $this->pro);

        $this->runCommand();

        $this->assertSame(["{$source->id}:" . User::DEFAULT_AI_LANGUAGE], $this->queued());
    }

    /** 同 T05 pm 裁定 1：不支援的 AI 語言（舊資料）→ en。 */
    public function testAnUnsupportedAiLanguageIsTreatedAsEnglish(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'xx-LANGSECRETX', $this->pro);

        $this->runCommand();

        $this->assertSame(["{$source->id}:en"], $this->queued());
        $this->assertSame(0, SourceSkill::query()->where('language', 'like', '%LANGSECRETX%')->count());
    }

    public function testS36AFreshGeneratingRowIsNotQueuedAgain(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'en', $this->pro);
        $this->row($source, 'en', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(14)]);

        $this->runCommand();

        $this->assertSame([], $this->queued());
    }

    // ── 首次／重試 ────────────────────────────────

    public function testAStaleGeneratingRowIsRequeued(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'en', $this->pro);
        $row = $this->row($source, 'en', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(16)]);

        $this->runCommand();

        $this->assertSame(["{$source->id}:en"], $this->queued());
        $this->assertTrue($row->fresh()->started_at->equalTo(now()));
    }

    /** @dataProvider failedAges */
    public function testAFailedRowIsRetriedAfter24Hours(int $hours, bool $retried): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'en', $this->pro);
        $this->row($source, 'en', SourceSkill::STATUS_FAILED, ['failed_at' => now()->subHours($hours)]);

        $this->runCommand();

        $this->assertSame($retried ? ["{$source->id}:en"] : [], $this->queued());
    }

    /** @return array<string, array{0: int, 1: bool}> failed_at 幾小時前 → 是否重試 */
    public static function failedAges(): array
    {
        return ['23 hours' => [23, false], 'exactly 24 hours' => [24, true], '25 hours' => [25, true]];
    }

    /** 有舊版、最近重產失敗：同樣 24 小時後重試，而且排入時不能清掉舊版。 */
    public function testRetryingAFailedRegenerationKeepsThePreviousVersion(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'en', $this->pro);
        $this->row($source, 'en', SourceSkill::STATUS_FAILED, [
            'content' => 'OLD CONTENT', 'generated_at' => now()->subDays(2), 'failed_at' => now()->subDays(1)->subMinute(),
        ]);

        $this->runCommand();

        $this->assertSame(["{$source->id}:en"], $this->queued());
        $this->assertSame('OLD CONTENT', SourceSkill::query()->where('source_id', $source->id)->value('content'));
    }

    /** @dataProvider updateRules */
    public function testS11UpdateRule(int $days, int $new, bool $regenerated): void
    {
        $source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL, 'status' => Source::STATUS_ACTIVE]);
        $old = $this->videos($source, 30, Carbon::parse('2026-01-01'));
        $this->videos($source, $new, Carbon::parse('2026-06-01'));
        $this->subscriber($source, 'en', $this->pro);
        $this->row($source, 'en', SourceSkill::STATUS_COMPLETED, [
            'content'      => 'OLD CONTENT',
            'generated_at' => now()->subDays($days),
            'media_ids'    => array_map(fn (Media $m) => $m->id, $old),
            'video_count'  => 30,
        ]);

        $this->runCommand();

        $this->assertSame($regenerated ? ["{$source->id}:en"] : [], $this->queued());
        $row = SourceSkill::query()->where('source_id', $source->id)->where('language', 'en')->firstOrFail();
        $this->assertSame('OLD CONTENT', $row->content, 'queueing never clears the previous version');
        $this->assertSame($regenerated ? SourceSkill::STATUS_GENERATING : SourceSkill::STATUS_COMPLETED, $row->status);
    }

    // ── S11：更新規則 ───────────────────────────────

    /** @return array<string, array{0: int, 1: int, 2: bool}> */
    public static function updateRules(): array
    {
        return [
            '8 days, 5 new'  => [8, 5, true],
            '8 days, 4 new'  => [8, 4, false],
            '7 days, 5 new'  => [7, 5, true],
            '6 days, 10 new' => [6, 10, false],
        ];
    }

    // ── S12：回填限速與排序 ───────────────────────────

    public function testS12EachRunQueuesAtMostTheBatchSizeWithoutRepeats(): void
    {
        config(['skills.batch_size' => 20]);
        $sources = [];
        for ($i = 0; $i < 25; ++$i) {
            $sources[] = $source = $this->channel();
            $this->subscriber($source, 'en', $this->pro);
        }

        $this->runCommand();
        $first = $this->queued();
        $this->assertCount(20, $first);
        $this->assertSame(20, $this->generatingRows());

        $this->runCommand();
        $all = $this->queued();
        $this->assertCount(25, $all, 'the second run queues the remaining 5');
        $this->assertSame($all, array_values(array_unique($all)), 'no group is queued twice');
    }

    public function testS12MoreEligibleSubscribersGoFirstThenSourceId(): void
    {
        config(['skills.batch_size' => 2]);
        $one = $this->channel();
        $three = $this->channel();
        $two = $this->channel();
        $twoToo = $this->channel();
        $this->subscriber($one, 'en', $this->pro);
        for ($i = 0; $i < 3; ++$i) {
            $this->subscriber($three, 'en', $this->pro);
        }
        for ($i = 0; $i < 2; ++$i) {
            $this->subscriber($two, 'en', $this->pro);
            $this->subscriber($twoToo, 'en', $this->pro);
        }
        // 免費訂閱者不算合格訂閱者
        for ($i = 0; $i < 5; ++$i) {
            $this->subscriber($one, 'en', null);
        }
        $tie = [$two->id, $twoToo->id];
        sort($tie);

        $this->runCommand();

        $this->assertSame(["{$three->id}:en", "{$tie[0]}:en"], $this->queued());
    }

    public function testFirstTimeAndRetriesGoBeforeUpdates(): void
    {
        config(['skills.batch_size' => 1]);
        $update = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL, 'status' => Source::STATUS_ACTIVE]);
        $old = $this->videos($update, 30, Carbon::parse('2026-01-01'));
        $this->videos($update, 10, Carbon::parse('2026-06-01'));
        for ($i = 0; $i < 5; ++$i) {
            $this->subscriber($update, 'en', $this->pro);
        }
        $this->row($update, 'en', SourceSkill::STATUS_COMPLETED, [
            'content' => 'OLD', 'generated_at' => now()->subDays(30), 'media_ids' => array_map(fn (Media $m) => $m->id, $old),
        ]);
        $first = $this->channel();
        $this->subscriber($first, 'en', $this->pro);

        $this->runCommand();

        $this->assertSame(["{$first->id}:en"], $this->queued());
    }

    // ── 全域每日上限（T13 守門）───────────────────────

    public function testTheGlobalDailyLimitStopsQueueingWithoutLeavingPlaceholders(): void
    {
        config(['skills.daily_global_limit' => 2]);
        for ($i = 0; $i < 5; ++$i) {
            $this->subscriber($this->channel(), 'en', $this->pro);
        }

        $this->runCommand();

        $this->assertCount(2, $this->queued());
        $this->assertSame(2, $this->generatingRows(), 'only the dispatched groups are marked generating');
    }

    public function testALimitOfZeroQueuesNothing(): void
    {
        config(['skills.daily_global_limit' => 0]);
        $this->subscriber($this->channel(), 'en', $this->pro);

        $this->runCommand();

        $this->assertSame([], $this->queued());
        $this->assertSame(0, $this->generatingRows());
    }

    // ── --dry-run --estimate ───────────────────────

    public function testDryRunQueuesNothingAndWritesNothing(): void
    {
        $source = $this->channel();
        $this->subscriber($source, 'en', $this->pro);
        $this->subscriber($source, 'ja', $this->pro);

        $this->artisan('sources:skills', ['--dry-run' => true, '--estimate' => true])
            ->expectsOutputToContain('2')
            ->assertExitCode(0);

        $this->assertSame([], $this->queued());
        $this->assertSame(0, SourceSkill::query()->count());
        $this->assertSame(0, (int) Cache::get('skills:dispatched:' . now('UTC')->format('Y-m-d'), 0), 'the daily counter is untouched');
    }

    // ── Kernel ───────────────────────────────────

    public function testItIsScheduledHourlyOnOneServerWithoutOverlapping(): void
    {
        $schedule = app(Schedule::class);
        app(Kernel::class)->schedule($schedule);

        $event = collect($schedule->events())->first(fn ($event) => str_contains((string) $event->command, 'sources:skills'));

        $this->assertNotNull($event, 'sources:skills is in the schedule');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }
    // ── pm 裁定 ───────────────────────────────────

    /** pm 裁定 2：排序看 (頻道, 語言) 組自己的合格訂閱者數，不是整個頻道的。 */
    public function testOrderingCountsEligibleSubscribersPerLanguageGroup(): void
    {
        config(['skills.batch_size' => 2]);
        $a = $this->channel();
        $b = $this->channel();
        for ($i = 0; $i < 3; ++$i) {
            $this->subscriber($a, 'ja', $this->pro);
        }
        $this->subscriber($a, 'en', $this->pro);
        $this->subscriber($b, 'en', $this->pro);
        $this->subscriber($b, 'en', $this->pro);

        $this->runCommand();

        // A 整個頻道有 4 位，但 (A, en) 這組只有 1 位，排在 (B, en) 的 2 位之後
        $this->assertSame(["{$a->id}:ja", "{$b->id}:en"], $this->queued());
    }

    /** pm 裁定 3：只帶 --dry-run，列出這一輪會排入的組（受 batch_size 限制），不派工、不寫 DB。 */
    public function testDryRunAloneListsThisRoundsGroupsWithinTheBatchSize(): void
    {
        config(['skills.batch_size' => 1]);
        $more = $this->channel();
        $less = $this->channel();
        $this->subscriber($more, 'en', $this->pro);
        $this->subscriber($more, 'en', $this->pro);
        $this->subscriber($less, 'en', $this->pro);

        $this->artisan('sources:skills', ['--dry-run' => true])
            ->expectsOutputToContain($more->id)
            ->doesntExpectOutputToContain($less->id)
            ->assertExitCode(0);

        $this->assertSame([], $this->queued());
        $this->assertSame(0, SourceSkill::query()->count());
    }

    /** pm 裁定 3：只帶 --estimate 視同 dry-run，絕不派工。 */
    public function testEstimateAloneNeverQueues(): void
    {
        $this->subscriber($this->channel(), 'en', $this->pro);

        $this->artisan('sources:skills', ['--estimate' => true])->assertExitCode(0);

        $this->assertSame([], $this->queued());
        $this->assertSame(0, SourceSkill::query()->count());
        $this->assertSame(0, (int) Cache::get('skills:dispatched:' . now('UTC')->format('Y-m-d'), 0));
    }

    /** pm 裁定 1：組數分首次／重試／更新，輸出 token 上限 = 組數 × 8000，金額依 AiModel 單價。 */
    public function testEstimateReportsGroupsTokensAndCost(): void
    {
        AiModel::query()->create([
            'name' => 'Estimate model', 'provider_model' => 'qa/estimate-model', 'input_price' => 1.0, 'output_price' => 2.0, 'enabled' => true,
        ]);
        $first = $this->channel();
        $this->subscriber($first, 'en', $this->pro);
        $retry = $this->channel();
        $this->subscriber($retry, 'en', $this->pro);
        $this->row($retry, 'en', SourceSkill::STATUS_FAILED, ['failed_at' => now()->subDays(2)]);
        $update = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL, 'status' => Source::STATUS_ACTIVE]);
        $old = $this->videos($update, 30, Carbon::parse('2026-01-01'));
        $this->videos($update, 5, Carbon::parse('2026-06-01'));
        $this->subscriber($update, 'en', $this->pro);
        $this->row($update, 'en', SourceSkill::STATUS_COMPLETED, [
            'content' => 'OLD', 'generated_at' => now()->subDays(8), 'media_ids' => array_map(fn (Media $m) => $m->id, $old),
        ]);

        $output = $this->commandOutput(['--estimate' => true, '--model' => 'qa/estimate-model']);

        $this->assertMatchesRegularExpression('/first\D*1\b/i', $output);
        $this->assertMatchesRegularExpression('/retry\D*1\b/i', $output);
        $this->assertMatchesRegularExpression('/update\D*1\b/i', $output);
        $this->assertMatchesRegularExpression('/\b24,?000\b/', $output, 'output token ceiling = 3 groups x 8000');
        $this->assertMatchesRegularExpression('/input\D*[1-9][\d,]*/i', $output, 'a non-zero input token estimate');
        // 輸出上限的費用就有 24000 / 1e6 × $2 = $0.048，預估金額至少是這個數
        preg_match_all('/\$\s?(\d+(?:\.\d+)?)/', $output, $amounts);
        $this->assertNotEmpty($amounts[1], 'a dollar amount');
        $this->assertGreaterThanOrEqual(0.048, (float) $amounts[1][0]);
        $this->assertStringContainsString('3', $output, 'the retry ceiling (x3) is shown');
        $this->assertSame([], $this->queued());
    }

    /** pm 裁定 1：查不到單價 → 金額 unknown，token 照常輸出，exit 0。 */
    public function testEstimateWithAnUnpricedModelShowsUnknownCost(): void
    {
        $this->subscriber($this->channel(), 'en', $this->pro);

        $output = $this->commandOutput(['--estimate' => true, '--model' => 'qa/no-such-model'], 0);

        $this->assertStringContainsString('unknown', $output);
        $this->assertMatchesRegularExpression('/\b8,?000\b/', $output, 'tokens are still shown');
    }

    /** 跑指令並取回輸出（Artisan facade 拿得到最後一次的輸出）。 */
    private function commandOutput(array $options, int $expectedExit = 0): string
    {
        $exit = Artisan::call('sources:skills', $options);
        $this->assertSame($expectedExit, $exit);

        return Artisan::output();
    }
}
