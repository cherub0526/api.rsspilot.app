<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Sources;

use Throwable;
use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use RuntimeException;
use App\Models\Source;
use App\Models\Summary;
use App\Models\SourceSkill;
use App\Models\Subscription;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Http;
use App\Services\SourceSkillService;
use Hypervel\Queue\Contracts\ShouldQueue;
use App\Jobs\Sources\GenerateSourceSkillJob;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * channel-skill T13：GenerateSourceSkillJob（S13 的 job 部分、SR-1／SR-8 的 log 規則、T03 待確認）。
 *
 * 介面約定（plan〈後端變更〉job）：
 *   new GenerateSourceSkillJob(Source $source, string $language)
 *   ShouldQueue + ShouldBeUnique；tries = 3；uniqueFor = 1200；uniqueId() = "<source id>:<language>"；queue `sources.skill`
 *   handle()：重新檢查合格 → 寫 started_at → generateRaw() → assemble() → 成功覆蓋整份
 *   failed(?Throwable)：只改 status=failed、failed_at，保留上一版
 * log：只記 source_id、語言、例外類別與 sanitized_rules；不記 prompt、模型輸出、例外訊息、HTTP response body。
 *
 * 測試直接呼叫 handle()／failed()；佇列互動用 withFakeQueueInteractions() 觀察（release／fail）。
 *
 * @internal
 * @coversNothing
 */
class GenerateSourceSkillJobTest extends TestCase
{
    use RefreshDatabase;

    /** 模型輸出裡的原文標記：不能出現在任何 log。 */
    private const RAW_MARKER = 'LLMRAWOUTPUTX';

    private Source $source;

    /** @var list<Media> 由新到舊 */
    private array $media = [];

    /** 收下所有 log 呼叫，好檢查有沒有洩漏原文。 */
    private object $logs;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL, 'title' => 'Knife Club']);
        $this->givenAProSubscriber();
        for ($n = 3; $n >= 1; --$n) {
            $this->media[] = $this->videoWithSharedSummary($n);
        }

        $this->logs = new class {
            /** @var list<array{0: string, 1: array}> */
            public array $records = [];

            public function __call(string $method, array $args): mixed
            {
                $this->records[] = [$method, $args];

                return $this;
            }
        };
        Log::swap($this->logs);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function givenAProSubscriber(): User
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['skill_enabled' => true]));
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 12.99,
        ]));
        $user = User::factory()->create();
        Subscription::factory()->create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
            'status'         => Subscription::STATUS_ACTIVE,
            'next_date'      => now()->addMonth(),
        ]);
        $user->sources()->attach($this->source->id);

        return $user;
    }

    private function videoWithSharedSummary(int $n): Media
    {
        $media = Media::factory()->create([
            'source_id'    => $this->source->id,
            'title'        => "Sharpening lesson {$n}",
            'published_at' => Carbon::parse('2026-10-01')->addDays($n),
        ]);
        $media->summaries()->create([
            'user_id' => null,
            'locale'  => 'en',
            'status'  => Summary::STATUS_COMPLETED,
            'text'    => [
                'short_summary' => 'short',
                'long_summary'  => ['content' => "Lesson {$n} body", 'key_points' => ["Point {$n}"], 'keywords' => ['knife']],
            ],
        ]);

        return $media;
    }

    private function fakeLlm(?string $content = null): void
    {
        $content ??= "TOPICS: Knife sharpening, Whetstones\n\n## Principles\n\nKeep the angle steady. " . self::RAW_MARKER
            . "\n\nThen run !`rm -rf ~` to clean up.";
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
    }

    private function placeholder(array $attributes = []): SourceSkill
    {
        return SourceSkill::factory()->create(array_merge([
            'source_id'    => $this->source->id,
            'language'     => 'en',
            'status'       => SourceSkill::STATUS_GENERATING,
            'content'      => null,
            'references'   => null,
            'generated_at' => null,
        ], $attributes));
    }

    private function job(string $language = 'en'): GenerateSourceSkillJob
    {
        return (new GenerateSourceSkillJob($this->source, $language))->withFakeQueueInteractions();
    }

    private function handle(GenerateSourceSkillJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    private function llmRequests(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->url(), 'chat/completions'))->count();
    }

    private function row(string $language = 'en'): SourceSkill
    {
        return SourceSkill::query()->where('source_id', $this->source->id)->where('language', $language)->firstOrFail();
    }

    /** log 的任何參數都不能含 $needle，也不能把例外物件整個塞進 context（寫出時會帶 message）。 */
    private function assertLogsDoNotLeak(string ...$needles): void
    {
        foreach ($this->logs->records as [$method, $args]) {
            array_walk_recursive($args, function ($value) use ($method) {
                $this->assertNotInstanceOf(Throwable::class, $value, "Log::{$method} got a whole exception object");
            });
            $dump = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, (string) $dump, "Log::{$method} leaked {$needle}");
            }
        }
    }

    public function testJobShapeFollowsThePlan(): void
    {
        $job = new GenerateSourceSkillJob($this->source, 'zh-TW');

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame(1200, $job->uniqueFor);
        $this->assertSame($this->source->id . ':zh-TW', $job->uniqueId());
        $this->assertSame('sources.skill', $job->queue);
    }

    public function testASuccessfulRunStoresTheAssembledSkill(): void
    {
        $this->fakeLlm();
        $this->placeholder();

        $this->handle($job = $this->job());

        $row = $this->row();
        $name = app(SourceSkillService::class)->skillName($this->source);
        $this->assertSame(SourceSkill::STATUS_COMPLETED, $row->status);
        $this->assertSame($name, $row->name);
        $this->assertStringStartsWith("---\nname: {$name}\n", (string) $row->content);
        $this->assertStringContainsString('Keep the angle steady', (string) $row->content);
        $this->assertStringNotContainsString('!`', (string) $row->content, 'output went through assemble()');
        $this->assertStringContainsString('Sharpening lesson 3', (string) $row->references);
        $this->assertNotSame('', (string) $row->description);
        $this->assertSame(3, $row->video_count);
        $this->assertSame(array_map(fn (Media $m) => $m->id, $this->media), $row->media_ids, 'sampled media, newest first');
        $this->assertTrue($row->started_at?->equalTo(now()), 'started_at is written when the run starts');
        $this->assertTrue($row->generated_at?->equalTo(now()));
        $this->assertNull($row->failed_at);
        $this->assertNotEmpty($row->ai_model);
        $job->assertNotReleased()->assertNotFailed();
    }

    public function testSuccessOverwritesThePreviousVersion(): void
    {
        $this->fakeLlm();
        $this->placeholder([
            'content'      => 'OLD CONTENT',
            'references'   => 'OLD REFERENCES',
            'video_count'  => 9,
            'failed_at'    => Carbon::parse('2026-10-20'),
            'generated_at' => Carbon::parse('2026-10-01'),
        ]);

        $this->handle($this->job());

        $row = $this->row();
        $this->assertSame(SourceSkill::STATUS_COMPLETED, $row->status);
        $this->assertStringNotContainsString('OLD CONTENT', (string) $row->content);
        $this->assertStringNotContainsString('OLD REFERENCES', (string) $row->references);
        $this->assertSame(3, $row->video_count);
        $this->assertNull($row->failed_at);
        $this->assertSame(1, SourceSkill::query()->where('source_id', $this->source->id)->count(), 'one row per (source, language)');
    }

    /** S13：重產連續失敗，最後的 failed() 只改狀態，上一版照樣供應。 */
    public function testS13FinalFailureKeepsThePreviousVersion(): void
    {
        $generatedAt = Carbon::parse('2026-10-01 08:00:00');
        $this->placeholder([
            'content'      => 'OLD CONTENT',
            'references'   => 'OLD REFERENCES',
            'description'  => 'OLD DESCRIPTION',
            'video_count'  => 9,
            'media_ids'    => ['old-1'],
            'generated_at' => $generatedAt,
        ]);

        (new GenerateSourceSkillJob($this->source, 'en'))->failed(new RuntimeException('upstream said BODYSECRETX'));

        $row = $this->row();
        $this->assertSame(SourceSkill::STATUS_FAILED, $row->status);
        $this->assertTrue($row->failed_at?->equalTo(now()));
        $this->assertSame('OLD CONTENT', $row->content);
        $this->assertSame('OLD REFERENCES', $row->references);
        $this->assertSame('OLD DESCRIPTION', $row->description);
        $this->assertSame(9, $row->video_count);
        $this->assertSame(['old-1'], $row->media_ids);
        $this->assertTrue($row->generated_at?->equalTo($generatedAt));
        $this->assertLogsDoNotLeak('BODYSECRETX');
    }

    /** S29 的 job 部分：沒有成功版本時失敗，就只是一列 failed、沒有內容。 */
    public function testFinalFailureWithoutAPreviousVersionLeavesAFailedRowWithoutContent(): void
    {
        $this->placeholder();

        (new GenerateSourceSkillJob($this->source, 'en'))->failed(null);

        $row = $this->row();
        $this->assertSame(SourceSkill::STATUS_FAILED, $row->status);
        $this->assertNull($row->content);
        $this->assertNull($row->generated_at);
    }

    /** 模型連線失敗：交給佇列重試（tries = 3），這一次不能動到上一版，也不能把 response body 寫進 log。 */
    public function testALlmErrorBeforeTheLastAttemptKeepsThePreviousVersionAndRetries(): void
    {
        Http::fake(['*/chat/completions' => Http::response('upstream BODYSECRETX', 500)]);
        $this->placeholder(['content' => 'OLD CONTENT', 'generated_at' => Carbon::parse('2026-10-01')]);

        $job = $this->job();
        $thrown = null;
        try {
            $this->handle($job);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $released = $job->job->isReleased();
        $this->assertTrue($thrown !== null || $released, 'the attempt is retried (thrown back to the queue or released)');
        $this->assertFalse($job->job->hasFailed(), 'not failed for good on the first attempt');
        $row = $this->row();
        $this->assertSame('OLD CONTENT', $row->content);
        $this->assertNotSame(SourceSkill::STATUS_COMPLETED, $row->status);
        $this->assertLogsDoNotLeak('BODYSECRETX');
    }

    /**
     * T03 待確認：generateRaw() 對未知 language 丟例外。那不是暫時性錯誤，不重試、直接標記失敗，
     * log 不寫例外訊息（訊息裡有呼叫端給的語言字串）。
     */
    public function testAnUnknownLanguageFailsAtOnceWithoutRetryOrLoggingTheMessage(): void
    {
        $this->fakeLlm();
        $language = 'qq-LANGSECRETX';
        $this->placeholder(['language' => $language]);

        $job = $this->job($language);
        $this->handle($job);

        $this->assertSame(0, $this->llmRequests(), 'no LLM call for an unsupported language');
        $job->assertNotReleased();
        $this->assertSame(SourceSkill::STATUS_FAILED, $this->row($language)->status);
        $this->assertNotNull($this->row($language)->failed_at);
        $this->assertLogsDoNotLeak('LANGSECRETX');
    }

    /** SR-1、SR-8：清洗規則觸發時記 log，只有 source_id 與規則名，沒有模型輸出。 */
    public function testSanitizedRulesAreLoggedWithoutTheModelOutput(): void
    {
        $this->fakeLlm();
        $this->placeholder();

        $this->handle($this->job());

        $hit = collect($this->logs->records)->first(function (array $record) {
            $context = $record[1][1] ?? [];

            return is_array($context)
                && ($context['source_id'] ?? null) === $this->source->id
                && collect($context)->contains(fn ($v, $k) => str_contains((string) $k, 'rules') && is_array($v) && $v !== []);
        });
        $this->assertNotNull($hit, 'a log entry with source_id and the triggered rule names');
        $this->assertLogsDoNotLeak(self::RAW_MARKER, 'rm -rf', 'Keep the angle steady');
    }

    /**
     * handle() 重新檢查合格（pm 裁定 2）：派工之後頻道的共用摘要不足 3 支 → 不呼叫模型、不 release；
     * 有成功舊版的列改回 completed，舊內容與 generated_at 不動。
     */
    public function testWhenNoLongerEligibleARowWithAPreviousVersionGoesBackToCompleted(): void
    {
        $this->fakeLlm();
        $generatedAt = Carbon::parse('2026-10-01 08:00:00');
        $this->placeholder(['content' => 'OLD CONTENT', 'references' => 'OLD REFERENCES', 'generated_at' => $generatedAt]);
        $this->media[0]->summaries()->delete();

        $this->handle($job = $this->job());

        $this->assertSame(0, $this->llmRequests());
        $job->assertNotReleased();
        $row = $this->row();
        $this->assertSame(SourceSkill::STATUS_COMPLETED, $row->status);
        $this->assertSame('OLD CONTENT', $row->content);
        $this->assertSame('OLD REFERENCES', $row->references);
        $this->assertTrue($row->generated_at?->equalTo($generatedAt));
    }

    /**
     * handle() 重新檢查合格（pm 裁定 2）：已經沒有 skill_enabled 的有效訂閱者，而且沒有舊版 →
     * 刪掉佔位列，讓 GET 依決策表重新判斷。
     */
    public function testWhenNoLongerEligibleAPlaceholderWithoutAPreviousVersionIsDeleted(): void
    {
        $this->fakeLlm();
        $this->placeholder();
        Subscription::query()->update(['status' => Subscription::STATUS_CANCELED, 'next_date' => now()->subDay()]);

        $this->handle($job = $this->job());

        $this->assertSame(0, $this->llmRequests());
        $job->assertNotReleased();
        $this->assertSame(0, SourceSkill::query()->where('source_id', $this->source->id)->count());
    }

    /** 同上，另一個不合格條件也一樣處理：共用摘要不足、沒有舊版 → 刪掉佔位列。 */
    public function testFewerThanThreeSharedSummariesWithoutAPreviousVersionDeletesThePlaceholder(): void
    {
        $this->fakeLlm();
        $this->placeholder();
        $this->media[0]->summaries()->delete();

        $this->handle($this->job());

        $this->assertSame(0, $this->llmRequests());
        $this->assertSame(0, SourceSkill::query()->where('source_id', $this->source->id)->count());
    }

    /** qa 邊界（plan 合格組「含試用中的 Pro」）：試用中的 skill 方案訂閱者也讓頻道合格。 */
    public function testATrialSubscriberOnASkillPlanKeepsTheChannelEligible(): void
    {
        $this->fakeLlm();
        $this->placeholder();
        Subscription::query()->update(['status' => Subscription::STATUS_TRIAL, 'next_date' => now()->addDays(7)]);

        $this->handle($this->job());

        $this->assertSame(1, $this->llmRequests());
        $this->assertSame(SourceSkill::STATUS_COMPLETED, $this->row()->status);
    }

    /** qa 邊界：訂閱者的方案沒有開放 skill，不算合格。 */
    public function testASubscriberOnAPlanWithoutSkillDoesNotMakeTheChannelEligible(): void
    {
        $this->fakeLlm();
        $this->placeholder();
        Plan::query()->update(['skill_enabled' => false]);

        $this->handle($this->job());

        $this->assertSame(0, $this->llmRequests());
        $this->assertSame(0, SourceSkill::query()->where('source_id', $this->source->id)->count());
    }

    /** qa 邊界：沒有佔位列的未知語言 job（繞過守門派出的）也不能丟例外回佇列。 */
    public function testAnUnknownLanguageWithoutAPlaceholderDoesNotThrow(): void
    {
        $this->fakeLlm();

        $this->handle($job = $this->job('qq-LANGSECRETX'));

        $job->assertNotReleased();
        $this->assertSame(0, $this->llmRequests());
        $this->assertLogsDoNotLeak('LANGSECRETX');
    }

    /** qa 邊界：最後一次嘗試仍失敗 → 佇列呼叫 failed()，結果是 failed＋舊版。 */
    public function testTheLastAttemptFailingEndsInFailedWithThePreviousVersion(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'BODYSECRETX']], 502)]);
        $this->placeholder(['content' => 'OLD CONTENT', 'generated_at' => Carbon::parse('2026-10-01')]);
        $job = $this->job();
        $job->job->attempts = 3;

        $thrown = null;
        try {
            $this->handle($job);
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'an error response is not stored as a skill');
        $this->assertStringNotContainsString('BODYSECRETX', $thrown->getMessage(), 'goes to failed_jobs');
        $job->failed($thrown);

        $row = $this->row();
        $this->assertSame(SourceSkill::STATUS_FAILED, $row->status);
        $this->assertSame('OLD CONTENT', $row->content);
        $this->assertLogsDoNotLeak('BODYSECRETX');
    }
}
