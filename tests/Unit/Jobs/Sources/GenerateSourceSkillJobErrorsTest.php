<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Sources;

use Mockery;
use Tests\TestCase;
use RuntimeException;
use App\Models\Source;
use App\Models\SourceSkill;
use Hypervel\Support\Facades\Http;
use App\Services\SourceSkillService;
use App\Services\SourceSkill\Sanitizer;
use App\Jobs\Sources\GenerateSourceSkillJob;
use App\Services\SourceSkill\SummarySampler;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\SourceSkill\UnstableOutputException;
use App\Services\SourceSkill\GenerationFailedException;

/**
 * qa 的 GenerateSourceSkillJobTest 之外：丟回佇列的例外本身也不能帶原文。
 * 佇列 worker 會把例外訊息寫進 log 與 failed_jobs，那不經過 job 自己的 Log 呼叫。
 *
 * @internal
 * @coversNothing
 */
class GenerateSourceSkillJobErrorsTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL]);
        SourceSkill::factory()->create([
            'source_id'    => $this->source->id,
            'language'     => 'en',
            'status'       => SourceSkill::STATUS_GENERATING,
            'content'      => null,
            'references'   => null,
            'generated_at' => null,
        ]);
    }

    private function handle(SourceSkillService $service): GenerateSourceSkillJob
    {
        $job = (new GenerateSourceSkillJob($this->source, 'en'))->withFakeQueueInteractions();
        $job->handle($service);

        return $job;
    }

    /** @return Mockery\MockInterface&SourceSkillService */
    private function eligibleService(): SourceSkillService
    {
        $service = Mockery::mock(SourceSkillService::class, [app(Sanitizer::class), app(SummarySampler::class)])->makePartial();
        $service->shouldReceive('isEligible')->andReturn(true);

        return $service;
    }

    public function testTheExceptionHandedBackToTheQueueCarriesNoResponseBody(): void
    {
        Http::fake(['*/chat/completions' => Http::response('upstream BODYSECRETX', 500)]);

        try {
            $this->handle($this->eligibleService());
            $this->fail('expected the attempt to be handed back to the queue');
        } catch (GenerationFailedException $e) {
            $this->assertStringNotContainsString('BODYSECRETX', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'a chained exception would be logged with its message');
        }
    }

    /** 錯誤回應的 JSON 沒有 choices 時 Completion 不會丟例外、只回空字串；空輸出不能存成一份空的 skill。 */
    public function testAnEmptyModelOutputIsRetriedInsteadOfStored(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'BODYSECRETX']], 502)]);

        $this->expectException(GenerationFailedException::class);
        try {
            $this->handle($this->eligibleService());
        } finally {
            $row = SourceSkill::query()->where('source_id', $this->source->id)->firstOrFail();
            $this->assertSame(SourceSkill::STATUS_GENERATING, $row->status);
            $this->assertNull($row->content);
        }
    }

    /** 清洗跑不到穩定代表輸出是刻意構造的，重跑同樣的取材沒有意義：直接標失敗。 */
    public function testUnstableOutputFailsAtOnceWithoutRetry(): void
    {
        $service = $this->eligibleService();
        $service->shouldReceive('generateRaw')->andReturn('## A');
        $service->shouldReceive('assemble')->andThrow(new UnstableOutputException('x'));

        $job = $this->handle($service);

        $job->assertNotReleased();
        $row = SourceSkill::query()->where('source_id', $this->source->id)->firstOrFail();
        $this->assertSame(SourceSkill::STATUS_FAILED, $row->status);
        $this->assertNotNull($row->failed_at);
    }

    // ── T13 r1 ──────────────────────────────────────────────

    /**
     * security 待確認 3：存檔失敗的 QueryException 訊息帶 SQL bindings（整份 skill 內容），
     * 一樣只能以類別名稱交回佇列。這裡讓存檔那一段丟出帶原文的例外來模擬。
     */
    public function testAFailureWhileStoringTheSkillIsHandedBackWithoutItsMessage(): void
    {
        $service = $this->eligibleService();
        $service->shouldReceive('generateRaw')->andReturn('## A');
        $service->shouldReceive('modelName')->andThrow(new RuntimeException('insert into source_skills ... BODYSECRETX'));

        try {
            $this->handle($service);
            $this->fail('expected the attempt to be handed back to the queue');
        } catch (GenerationFailedException $e) {
            $this->assertStringNotContainsString('BODYSECRETX', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $row = SourceSkill::query()->where('source_id', $this->source->id)->firstOrFail();
        $this->assertSame(SourceSkill::STATUS_GENERATING, $row->status);
    }

    /** qa Info 1：繞過派工守門、沒有佔位列的未知語言 job，不能把那個字串寫成一列。 */
    public function testAnUnknownLanguageWithoutAPlaceholderCreatesNoRow(): void
    {
        $language = str_repeat('qq-', 200);

        $job = (new GenerateSourceSkillJob($this->source, $language))->withFakeQueueInteractions();
        $job->handle(app(SourceSkillService::class));

        $job->assertNotReleased()->assertNotFailed();
        $this->assertSame(0, SourceSkill::query()->where('language', $language)->count());
        $this->assertSame(1, SourceSkill::query()->where('source_id', $this->source->id)->count(), 'the en placeholder is untouched');
    }
}
