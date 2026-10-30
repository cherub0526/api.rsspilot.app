<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Sources;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\Summary;
use App\Models\SourceSkill;
use App\Models\Subscription;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Queue;
use App\Jobs\Sources\GenerateSourceSkillJob;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\Http\TestResponse;

/**
 * channel-skill T05：GET /v1/sources/{sourceId}/skill（plan〈API 契約〉與〈狀態決策表〉）。
 *
 * 介面約定：路由名稱 `api.v1.sources.skill.show`（`routes/v1.php` 的 sources 群組底下，`auth` + `throttle:60,1`）。
 * 語言一律取 `User::aiLanguageCode()`；Pro 一律以 `plans.skill_enabled` 判斷；插隊經 T13 的
 * `SourceSkillService::dispatchGeneration()`（全域每日上限）。
 *
 * 決策表由上而下、第一個命中的規則勝出；測試名稱帶規則編號（R1–R10）與 Scenario ID。
 *
 * @internal
 * @coversNothing
 */
class SkillControllerTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT = "---\nname: rsspilot-knife-club-abcdef\ndescription: Knife skills\n---\n\n"
        . "> disclaimer\n\n## Core principles\n\nKeep the angle steady. FULLCONTENTX\n\n## Checklist\n\n- Hone first\n";

    private const REFERENCES = "# Videos\n\n- Lesson REFERENCESX\n";

    private Plan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::flush();
        Carbon::setTestNow('2026-10-30 12:00:00');
        config(['skills.daily_global_limit' => 1000]);

        $this->proPlan = Plan::withoutEvents(fn () => Plan::factory()->create(['skill_enabled' => true]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixture ─────────────────────────────────────

    private function user(string $language, bool $pro): User
    {
        $user = User::factory()->create();
        $user->setting()->updateOrCreate([], ['data' => ['ai' => ['language' => $language]]]);

        if ($pro) {
            $price = Price::withoutEvents(fn () => Price::factory()->create([
                'plan_id' => $this->proPlan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 12.99,
            ]));
            Subscription::factory()->create([
                'user_id'        => $user->id,
                'plan_id'        => $this->proPlan->id,
                'price_id'       => $price->id,
                'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
                'status'         => Subscription::STATUS_ACTIVE,
                'next_date'      => now()->addMonth(),
            ]);
        }

        $this->actingAs($user);

        return $user;
    }

    private function pro(string $language = 'zh-TW'): User
    {
        return $this->user($language, true);
    }

    private function free(string $language = 'zh-TW'): User
    {
        return $this->user($language, false);
    }

    /** 頻道與 $summaries 支有共用摘要的影片；$subscriber 有給就訂閱它。 */
    private function channel(int $summaries = 5, ?User $subscriber = null, array $attributes = []): Source
    {
        $source = Source::factory()->create(array_merge([
            'type'   => Source::TYPE_YOUTUBE_CHANNEL,
            'status' => Source::STATUS_ACTIVE,
            'free'   => false,
            'title'  => 'Knife Club',
        ], $attributes));
        for ($n = 1; $n <= $summaries; ++$n) {
            $media = Media::factory()->create([
                'source_id'    => $source->id,
                'published_at' => Carbon::parse('2026-09-01')->addDays($n),
            ]);
            $media->summaries()->create([
                'user_id' => null,
                'locale'  => 'en',
                'status'  => Summary::STATUS_COMPLETED,
                'text'    => [
                    'short_summary' => 'short',
                    'long_summary'  => ['content' => "Body {$n}", 'key_points' => ['p'], 'keywords' => ['k']],
                ],
            ]);
        }
        $subscriber?->sources()->attach($source->id);

        return $source;
    }

    /** 一份成功版本（generated_at 有值、有內容）。 */
    private function version(Source $source, string $language, array $attributes = []): SourceSkill
    {
        return SourceSkill::factory()->create(array_merge([
            'source_id'    => $source->id,
            'language'     => $language,
            'name'         => 'rsspilot-knife-club-abcdef',
            'description'  => "Knife skills ({$language})",
            'content'      => self::CONTENT,
            'references'   => self::REFERENCES,
            'video_count'  => 30,
            'status'       => SourceSkill::STATUS_COMPLETED,
            'generated_at' => Carbon::parse('2026-10-01 00:00:00'),
        ], $attributes));
    }

    /** 沒有成功版本的資料列（generating／failed）。 */
    private function bare(Source $source, string $language, string $status, array $attributes = []): SourceSkill
    {
        return SourceSkill::factory()->create(array_merge([
            'source_id'    => $source->id,
            'language'     => $language,
            'name'         => null,
            'description'  => null,
            'content'      => null,
            'references'   => null,
            'video_count'  => 0,
            'status'       => $status,
            'generated_at' => null,
            'started_at'   => $status === SourceSkill::STATUS_GENERATING ? now()->subMinutes(5) : null,
            'failed_at'    => $status === SourceSkill::STATUS_FAILED ? now()->subHour() : null,
        ], $attributes));
    }

    private function show(Source $source): TestResponse
    {
        return $this->json('GET', route('api.v1.sources.skill.show', ['sourceId' => $source->id]));
    }

    private function assertNoJob(): void
    {
        Queue::assertNotPushed(GenerateSourceSkillJob::class);
    }

    private function rows(Source $source, ?string $language = null): int
    {
        return SourceSkill::query()->where('source_id', $source->id)
            ->when($language !== null, fn ($q) => $q->where('language', $language))->count();
    }

    /** SR-6：上鎖時整個回應都不能帶到內文或 references（不只是 content 欄位）。 */
    private function assertNoBody(TestResponse $response): void
    {
        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('FULLCONTENTX', $raw);
        $this->assertStringNotContainsString('REFERENCESX', $raw);
        $this->assertNull($response->json('skill.content'));
    }

    // ── 認證、存取權、標頭 ─────────────────────────────

    public function testS17Unauthenticated(): void
    {
        $source = $this->channel();

        $this->show($source)->assertStatus(401);
    }

    public function testS14NotSubscribedAndNotFreeIs404(): void
    {
        $this->pro();
        $source = $this->channel();
        $this->version($source, 'zh-TW');

        $this->show($source)->assertStatus(404);
        $this->assertNoJob();
    }

    public function testUnknownSourceIs404(): void
    {
        $this->pro();

        $this->json('GET', route('api.v1.sources.skill.show', ['sourceId' => '01jsvgt3prpypqwex4wj78bznk']))
            ->assertStatus(404);
    }

    public function testR1S41APlaylistIs404EvenWhenSubscribed(): void
    {
        $user = $this->pro();
        $source = $this->channel(5, $user, ['type' => Source::TYPE_YOUTUBE_PLAYLIST]);
        $this->version($source, 'zh-TW');

        $this->show($source)->assertStatus(404);
        $this->assertNoJob();
    }

    public function testResponsesAreNotCached(): void
    {
        $user = $this->pro();
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW');

        $cacheControl = (string) $this->show($source)->assertStatus(200)->getHeaderLine('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function testThrottledAtSixtyRequestsAMinute(): void
    {
        $user = $this->pro();
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW');

        for ($i = 0; $i < 60; ++$i) {
            $this->show($source)->assertStatus(200);
        }
        $this->show($source)->assertStatus(429);
    }

    // ── R2：共用摘要不足 ───────────────────────────────

    public function testR2S08FewerThanThreeSharedSummariesIsInsufficient(): void
    {
        $user = $this->pro();
        $source = $this->channel(2, $user);

        $this->show($source)->assertStatus(200)->assertJson([
            'status'           => 'insufficient',
            'reason'           => null,
            'min_videos'       => 3,
            'summarized_count' => 2,
            'skill'            => null,
        ]);
        $this->assertNoJob();
        $this->assertSame(0, $this->rows($source));
    }

    public function testR2InsufficientWinsForLockedUsersToo(): void
    {
        $user = $this->free();
        $source = $this->channel(2, $user);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'insufficient', 'locked' => true, 'skill' => null]);
    }

    /** 規則由上而下：摘要掉到 3 支以下時，就算有舊版也回 insufficient。 */
    public function testR2InsufficientWinsOverAnExistingVersion(): void
    {
        $user = $this->pro();
        $source = $this->channel(2, $user);
        $this->version($source, 'zh-TW');

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'insufficient', 'skill' => null]);
    }

    public function testSummarizedCountCountsVideosWithAUsableSharedSummary(): void
    {
        $user = $this->pro();
        $source = $this->channel(3, $user);
        $this->version($source, 'zh-TW');
        $custom = Media::factory()->create(['source_id' => $source->id]);
        $custom->summaries()->create(['user_id' => $user->id, 'locale' => 'en', 'status' => Summary::STATUS_COMPLETED,
            'text'                              => ['short_summary' => 's', 'long_summary' => ['content' => 'c', 'key_points' => [], 'keywords' => []]]]);
        $processing = Media::factory()->create(['source_id' => $source->id]);
        $processing->summaries()->create(['user_id' => null, 'locale' => 'en', 'status' => Summary::STATUS_PROCESSING]);
        // 一支影片兩筆共用摘要，只算一支
        Media::query()->where('source_id', $source->id)->first()->summaries()->create([
            'user_id' => null, 'locale' => 'zh-TW', 'status' => Summary::STATUS_COMPLETED,
            'text'    => ['short_summary' => 's', 'long_summary' => ['content' => 'c', 'key_points' => [], 'keywords' => []]],
        ]);

        $this->show($source)->assertStatus(200)->assertJson(['summarized_count' => 3]);
    }

    // ── R3、R4：上鎖（免費方案）───────────────────────────

    public function testR3S05LockedUsersSeeTheirOwnLanguageWithoutTheBody(): void
    {
        $user = $this->free('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW');
        $this->version($source, 'en');

        $response = $this->show($source)->assertStatus(200)->assertJson([
            'status'   => 'ready',
            'reason'   => null,
            'locked'   => true,
            'language' => 'zh-TW',
            'skill'    => [
                'name'        => 'rsspilot-knife-club-abcdef',
                'description' => 'Knife skills (zh-TW)',
                'outline'     => ['Core principles', 'Checklist'],
                'video_count' => 30,
            ],
        ]);
        $this->assertNoBody($response);
        $this->assertNoJob();
    }

    public function testR3S05LockedFallsBackToEnglishWhenTheirLanguageIsMissing(): void
    {
        $user = $this->free('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'ja', ['generated_at' => Carbon::parse('2026-10-20')]);
        $this->version($source, 'en', ['generated_at' => Carbon::parse('2026-10-01')]);

        $response = $this->show($source)->assertStatus(200)
            ->assertJson(['locked' => true, 'language' => 'en', 'skill' => ['description' => 'Knife skills (en)']]);
        $this->assertNoBody($response);
    }

    public function testR3S05LockedFallsBackToTheNewestVersionWithoutEnglish(): void
    {
        $user = $this->free('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'ja', ['generated_at' => Carbon::parse('2026-10-01')]);
        $this->version($source, 'ko', ['generated_at' => Carbon::parse('2026-10-20')]);

        $response = $this->show($source)->assertStatus(200)
            ->assertJson(['locked' => true, 'language' => 'ko', 'skill' => ['description' => 'Knife skills (ko)']]);
        $this->assertNoBody($response);
    }

    /** R3 的「該版本的對外狀態」：上一版還在、最近一次重產失敗 → failed。 */
    public function testR3LockedShowsFailedWhenTheChosenVersionsLastRegenerationFailed(): void
    {
        $user = $this->free('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW', ['status' => SourceSkill::STATUS_FAILED, 'failed_at' => now()->subHour()]);

        $response = $this->show($source)->assertStatus(200)->assertJson(['status' => 'failed', 'locked' => true]);
        $this->assertNotNull($response->json('skill'));
        $this->assertNoBody($response);
    }

    public function testR4S07LockedWithoutAnyVersionIsUpgradeRequiredAndNeverQueues(): void
    {
        $user = $this->free('zh-TW');
        $source = $this->channel(5, $user);

        $this->show($source)->assertStatus(200)->assertJson([
            'status'   => 'not_generated',
            'reason'   => 'upgrade_required',
            'locked'   => true,
            'language' => 'zh-TW',
            'skill'    => null,
        ]);
        $this->assertNoJob();
        $this->assertSame(0, $this->rows($source));
    }

    /** 失敗的資料列沒有內容，不算「成功版本」：上鎖的人看到的是 R4。 */
    public function testR4ARowWithoutAnyContentIsNotAVersion(): void
    {
        $user = $this->free('zh-TW');
        $source = $this->channel(5, $user);
        $this->bare($source, 'zh-TW', SourceSkill::STATUS_FAILED);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'not_generated', 'reason' => 'upgrade_required']);
    }

    public function testS39ADowngradedUserLosesTheBodyImmediately(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW');
        $this->show($source)->assertStatus(200)->assertJson(['locked' => false]);

        Subscription::query()->where('user_id', $user->id)->update(['status' => Subscription::STATUS_CANCELED, 'next_date' => now()->subDay()]);

        $response = $this->show($source)->assertStatus(200)->assertJson(['locked' => true]);
        $this->assertNoBody($response);
    }

    // ── R5：未鎖、自己的語言有成功版本 ─────────────────────

    public function testR5S01ProUsersGetTheFullSkill(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(30, $user);
        $this->version($source, 'zh-TW');

        $response = $this->show($source)->assertStatus(200)->assertJson([
            'status'   => 'ready',
            'reason'   => null,
            'locked'   => false,
            'language' => 'zh-TW',
            'skill'    => [
                'name'        => 'rsspilot-knife-club-abcdef',
                'description' => 'Knife skills (zh-TW)',
                'outline'     => ['Core principles', 'Checklist'],
                'content'     => self::CONTENT,
                'video_count' => 30,
            ],
        ]);
        $this->assertStringStartsWith('2026-10-01T', (string) $response->json('skill.generated_at'));
        $this->assertStringStartsWith("---\n", (string) $response->json('skill.content'));
        $this->assertStringNotContainsString('REFERENCESX', (string) $response->getContent(), 'references only go out through download');
        $this->assertNoJob();
    }

    public function testR5ARegenerationInProgressStillShowsReady(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW', ['status' => SourceSkill::STATUS_GENERATING, 'started_at' => now()->subMinutes(2)]);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'ready', 'skill' => ['content' => self::CONTENT]]);
        $this->assertNoJob();
    }

    public function testR5S13AFailedRegenerationShowsFailedWithThePreviousVersion(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW', ['status' => SourceSkill::STATUS_FAILED, 'failed_at' => now()->subHour()]);

        $this->show($source)->assertStatus(200)->assertJson([
            'status' => 'failed',
            'reason' => null,
            'skill'  => ['content' => self::CONTENT],
        ]);
        $this->assertNoJob();
    }

    /** 自己的語言有版本時不看別的語言；別的語言有版本也不算 R5（S09 那一條）。 */
    public function testR5OnlyTheirOwnLanguageCounts(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW');
        $this->version($source, 'en', ['description' => 'ENGLISHX']);

        $response = $this->show($source)->assertStatus(200)->assertJson(['language' => 'zh-TW']);
        $this->assertStringNotContainsString('ENGLISHX', (string) $response->getContent());
    }

    // ── R6、R7：未鎖、沒有成功版本、已有資料列 ──────────────

    public function testR6AFreshGeneratingRowIsGeneratingWithoutANewJob(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $row = $this->bare($source, 'zh-TW', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(14)]);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'generating', 'reason' => null, 'skill' => null]);
        $this->assertNoJob();
        $this->assertTrue($row->fresh()->started_at->equalTo(now()->subMinutes(14)), 'started_at untouched');
    }

    public function testR7S29AFailedRowWithoutAVersionIsFailedAndGetDoesNotRequeue(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $this->bare($source, 'zh-TW', SourceSkill::STATUS_FAILED, ['failed_at' => now()->subDays(3)]);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'failed', 'reason' => null, 'skill' => null]);
        $this->assertNoJob();
        $this->assertSame(SourceSkill::STATUS_FAILED, SourceSkill::query()->where('source_id', $source->id)->value('status'));
    }

    // ── R8：未訂閱的免費來源 ──────────────────────────────

    public function testS34ProUsersSeeAnExistingSkillOfAFreeSourceTheyDoNotFollow(): void
    {
        $this->pro('zh-TW');
        $source = $this->channel(5, null, ['free' => true]);
        $this->version($source, 'zh-TW');

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'ready', 'skill' => ['content' => self::CONTENT]]);
    }

    public function testR8S34AFreeSourceTheyDoNotFollowIsSubscribeRequiredAndNeverQueues(): void
    {
        $this->pro('zh-TW');
        $source = $this->channel(5, null, ['free' => true]);

        $this->show($source)->assertStatus(200)->assertJson([
            'status' => 'not_generated',
            'reason' => 'subscribe_required',
            'skill'  => null,
        ]);
        $this->assertNoJob();
        $this->assertSame(0, $this->rows($source));
    }

    // ── R9、R10：插隊 ─────────────────────────────────

    public function testR10S09AMissingLanguageIsQueuedOnceAndShowsGenerating(): void
    {
        $user = $this->pro('ja');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW');

        $this->show($source)->assertStatus(200)->assertJson([
            'status'   => 'generating',
            'reason'   => null,
            'language' => 'ja',
            'skill'    => null,
        ]);

        $row = SourceSkill::query()->where('source_id', $source->id)->where('language', 'ja')->firstOrFail();
        $this->assertSame(SourceSkill::STATUS_GENERATING, $row->status);
        $this->assertTrue($row->started_at?->equalTo(now()));
        Queue::assertPushed(GenerateSourceSkillJob::class, 1);
        Queue::assertPushed(GenerateSourceSkillJob::class, fn (GenerateSourceSkillJob $job) => $job->uniqueId() === $source->id . ':ja');

        // 再打開一次：資料列已是 generating，不再派第 2 個
        $this->show($source)->assertStatus(200)->assertJson(['status' => 'generating']);
        Queue::assertPushed(GenerateSourceSkillJob::class, 1);
        $this->assertSame(1, $this->rows($source, 'ja'));
    }

    public function testR10S42AStaleGeneratingRowIsRequeuedWithAFreshStartedAt(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $row = $this->bare($source, 'zh-TW', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(16)]);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'generating']);

        Queue::assertPushed(GenerateSourceSkillJob::class, 1);
        $this->assertTrue($row->fresh()->started_at->equalTo(now()), 'started_at updated');
        $this->assertSame(1, $this->rows($source, 'zh-TW'));
    }

    /** SR-5：每人每日插隊 10 次；第 11 個缺件的頻道回 daily_limit，不派工、不留佔位列。 */
    public function testR9S24TheEleventhQueueTodayIsDailyLimit(): void
    {
        $user = $this->pro('zh-TW');
        for ($i = 0; $i < 10; ++$i) {
            $this->show($this->channel(3, $user))->assertStatus(200)->assertJson(['status' => 'generating']);
        }
        Queue::assertPushed(GenerateSourceSkillJob::class, 10);

        $eleventh = $this->channel(3, $user);
        $this->show($eleventh)->assertStatus(200)->assertJson([
            'status' => 'not_generated',
            'reason' => 'daily_limit',
            'skill'  => null,
        ]);
        Queue::assertPushed(GenerateSourceSkillJob::class, 10);
        $this->assertSame(0, $this->rows($eleventh));
    }

    /** S24：切換 AI 語言後打開另一個頻道，同一個人的上限照算。 */
    public function testS24SwitchingLanguageDoesNotResetTheDailyLimit(): void
    {
        $user = $this->pro('zh-TW');
        for ($i = 0; $i < 10; ++$i) {
            $this->show($this->channel(3, $user));
        }
        $user->setting()->update(['data' => ['ai' => ['language' => 'ja']]]);

        $this->show($this->channel(3, $user))->assertStatus(200)->assertJson(['reason' => 'daily_limit']);
        Queue::assertPushed(GenerateSourceSkillJob::class, 10);
    }

    /** S42：逾時重派也計入每日 10 次。 */
    public function testS42RequeuingAStaleRowCountsTowardsTheDailyLimit(): void
    {
        $user = $this->pro('zh-TW');
        for ($i = 0; $i < 9; ++$i) {
            $this->show($this->channel(3, $user));
        }
        $stale = $this->channel(3, $user);
        $this->bare($stale, 'zh-TW', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(20)]);
        $this->show($stale)->assertStatus(200)->assertJson(['status' => 'generating']);
        Queue::assertPushed(GenerateSourceSkillJob::class, 10);

        $this->show($this->channel(3, $user))->assertStatus(200)->assertJson(['reason' => 'daily_limit']);
        Queue::assertPushed(GenerateSourceSkillJob::class, 10);
    }

    /** 上限是每個人各自的。 */
    public function testTheDailyLimitIsPerUser(): void
    {
        $first = $this->pro('zh-TW');
        $sources = [];
        for ($i = 0; $i < 10; ++$i) {
            $this->show($sources[] = $this->channel(3, $first));
        }

        $second = $this->pro('ja');
        $second->sources()->attach($sources[0]->id);
        $this->show($sources[0])->assertStatus(200)->assertJson(['status' => 'generating', 'language' => 'ja']);
        Queue::assertPushed(GenerateSourceSkillJob::class, 11);
    }

    /** R10 的但書：全域每日上限（T13 守門）已滿 → 回 R9，不派工，也不留下會讓下一次變成 R6 的佔位列。 */
    public function testR10TheGlobalDailyLimitTurnsQueueingIntoDailyLimit(): void
    {
        config(['skills.daily_global_limit' => 0]);
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'not_generated', 'reason' => 'daily_limit']);
        $this->show($source)->assertStatus(200)->assertJson(['status' => 'not_generated', 'reason' => 'daily_limit']);
        $this->assertNoJob();
        $this->assertSame(0, SourceSkill::query()->where('source_id', $source->id)->where('status', SourceSkill::STATUS_GENERATING)->count());
    }

    /** 被全域上限擋下的那一次不算進這個人的 10 次。 */
    public function testARefusedQueueDoesNotUseUpThePersonalLimit(): void
    {
        $user = $this->pro('zh-TW');
        config(['skills.daily_global_limit' => 0]);
        $this->show($this->channel(3, $user))->assertJson(['reason' => 'daily_limit']);
        config(['skills.daily_global_limit' => 1000]);

        for ($i = 0; $i < 10; ++$i) {
            $this->show($this->channel(3, $user))->assertStatus(200)->assertJson(['status' => 'generating']);
        }
        Queue::assertPushed(GenerateSourceSkillJob::class, 10);
    }

    /** 沒有 settings 的使用者用預設語言（en），與排程一致。 */
    public function testWithoutSettingsTheLanguageDefaultsToEnglish(): void
    {
        $user = $this->pro('zh-TW');
        $user->setting()->delete();
        $source = $this->channel(5, $user);

        $this->show($source)->assertStatus(200)->assertJson(['language' => 'en', 'status' => 'generating']);
        Queue::assertPushed(GenerateSourceSkillJob::class, fn (GenerateSourceSkillJob $job) => $job->uniqueId() === $source->id . ':en');
    }

    /**
     * T05 DoD（T03-security-r1 待確認 1）＋ pm 裁定 1：AI 語言不在 skill 支援清單（舊資料）→
     * 一律當成 en 走決策表；不替那個字串派工或建列。
     */
    public function testAnUnsupportedAiLanguageFallsBackToEnglish(): void
    {
        $user = $this->pro('xx-LANGSECRETX');
        $source = $this->channel(5, $user);

        $response = $this->show($source)->assertStatus(200)->assertJson(['language' => 'en', 'status' => 'generating']);

        $this->assertStringNotContainsString('LANGSECRETX', (string) $response->getContent());
        Queue::assertPushed(GenerateSourceSkillJob::class, 1);
        Queue::assertPushed(GenerateSourceSkillJob::class, fn (GenerateSourceSkillJob $job) => $job->uniqueId() === $source->id . ':en');
        $this->assertSame(1, $this->rows($source, 'en'));
        $this->assertSame(1, $this->rows($source), 'no row for the unsupported string');
    }

    /** pm 裁定 1：退回 en 時，已有的 en 版本照 R5 回 ready。 */
    public function testAnUnsupportedAiLanguageSeesTheEnglishVersion(): void
    {
        $user = $this->pro('xx');
        $source = $this->channel(5, $user);
        $this->version($source, 'en');

        $this->show($source)->assertStatus(200)->assertJson(['language' => 'en', 'status' => 'ready', 'skill' => ['content' => self::CONTENT]]);
        $this->assertNoJob();
    }

    /** pm 裁定 1：上鎖的人也一樣，自己的語言當成 en。 */
    public function testAnUnsupportedAiLanguageOfALockedUserPicksEnglishFirst(): void
    {
        $user = $this->free('xx');
        $source = $this->channel(5, $user);
        $this->version($source, 'ja', ['generated_at' => Carbon::parse('2026-10-20')]);
        $this->version($source, 'en', ['generated_at' => Carbon::parse('2026-10-01')]);

        $this->show($source)->assertStatus(200)->assertJson(['locked' => true, 'language' => 'en']);
    }

    /** pm 裁定 2：每人每日 10 次以 UTC 日期計算，過了 UTC 午夜重新計算。 */
    public function testThePersonalDailyLimitResetsAtMidnightUtc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 23:50:00', 'UTC'));
        $user = $this->pro('zh-TW');
        for ($i = 0; $i < 10; ++$i) {
            $this->show($this->channel(3, $user));
        }
        $next = $this->channel(3, $user);
        $this->show($next)->assertStatus(200)->assertJson(['reason' => 'daily_limit']);

        Carbon::setTestNow(Carbon::parse('2026-10-31 00:05:00', 'UTC'));
        $this->show($next)->assertStatus(200)->assertJson(['status' => 'generating']);
        Queue::assertPushed(GenerateSourceSkillJob::class, 11);
    }

    /** pm 裁定 5：上鎖時挑中的那一份正在背景重產，回 ready（同 R5）。 */
    public function testR3LockedShowsReadyWhileTheChosenVersionRegenerates(): void
    {
        $user = $this->free('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW', ['status' => SourceSkill::STATUS_GENERATING, 'started_at' => now()->subMinutes(2)]);

        $response = $this->show($source)->assertStatus(200)->assertJson(['status' => 'ready', 'locked' => true]);
        $this->assertNoBody($response);
    }
    // ── qa 邊界（T05 r1 審查）──────────────────────────

    /** 計數要跨過取材的一頁（60 支），不能停在第一頁。 */
    public function testSummarizedCountIsNotCappedAtOnePage(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(70, $user);
        $this->version($source, 'zh-TW');

        $this->show($source)->assertStatus(200)->assertJson(['summarized_count' => 70]);
    }

    /** 規則 5 先於 10：有舊版時，逾時的背景重產照樣回 ready，GET 不重派（交給排程）。 */
    public function testR5AStaleRegenerationWithAVersionIsReadyAndNotRequeuedByGet(): void
    {
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $this->version($source, 'zh-TW', ['status' => SourceSkill::STATUS_GENERATING, 'started_at' => now()->subHour()]);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'ready']);
        $this->assertNoJob();
    }

    /** 規則 6 先於 8：未追蹤的免費來源，別人插隊中的 generating 照樣顯示產生中。 */
    public function testR6AFreshGeneratingRowOnAFreeSourceTheyDoNotFollowIsGenerating(): void
    {
        $this->pro('zh-TW');
        $source = $this->channel(5, null, ['free' => true]);
        $this->bare($source, 'zh-TW', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(3)]);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'generating']);
        $this->assertNoJob();
    }

    /** 規則 8：未追蹤的免費來源，逾時的 generating 不由這個人重派。 */
    public function testR8AStaleGeneratingRowOnAFreeSourceTheyDoNotFollowIsNotRequeued(): void
    {
        $this->pro('zh-TW');
        $source = $this->channel(5, null, ['free' => true]);
        $row = $this->bare($source, 'zh-TW', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(30)]);

        $this->show($source)->assertStatus(200)->assertJson(['status' => 'not_generated', 'reason' => 'subscribe_required']);
        $this->assertNoJob();
        $this->assertTrue($row->fresh()->started_at->equalTo(now()->subMinutes(30)));
    }

    /** 全域上限擋下逾時重派：既有的列還原成原本的 started_at，不被當成剛開始。 */
    public function testARefusedRequeueRestoresTheExistingRow(): void
    {
        config(['skills.daily_global_limit' => 0]);
        $user = $this->pro('zh-TW');
        $source = $this->channel(5, $user);
        $row = $this->bare($source, 'zh-TW', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(20)]);

        $this->show($source)->assertStatus(200)->assertJson(['reason' => 'daily_limit']);

        $fresh = $row->fresh();
        $this->assertSame(SourceSkill::STATUS_GENERATING, $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo(now()->subMinutes(20)));
        $this->assertNoJob();
    }

    /** 個人上限擋下時也不動既有的列（不更新 started_at）。 */
    public function testThePersonalLimitLeavesAnExistingStaleRowUntouched(): void
    {
        $user = $this->pro('zh-TW');
        for ($i = 0; $i < 10; ++$i) {
            $this->show($this->channel(3, $user));
        }
        $source = $this->channel(5, $user);
        $row = $this->bare($source, 'zh-TW', SourceSkill::STATUS_GENERATING, ['started_at' => now()->subMinutes(20)]);

        $this->show($source)->assertStatus(200)->assertJson(['reason' => 'daily_limit']);

        $this->assertTrue($row->fresh()->started_at->equalTo(now()->subMinutes(20)));
        Queue::assertPushed(GenerateSourceSkillJob::class, 10);
    }
}
