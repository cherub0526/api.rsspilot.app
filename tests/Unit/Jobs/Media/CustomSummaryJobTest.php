<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Summary;
use App\Models\CustomPrompt;
use Hypervel\Support\Facades\Http;
use App\Jobs\Media\CustomSummaryJob;
use Tests\Concerns\BuildsCustomSummaryScenario;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Jobs\Media\CustomSummaryJob
 */
class CustomSummaryJobTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomSummaryScenario;

    /** OpenRouter 回一份格式正確的摘要。沒有 fake 的話測試會真的對外送請求。 */
    private function fakeCompletion(?string $content = null): void
    {
        $content ??= json_encode([
            'short_summary' => 'One line.',
            'long_summary'  => ['content' => 'Longer.', 'key_points' => ['a'], 'keywords' => ['k']],
        ]);

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $content]]],
        ])]);
    }

    private function runJob(Media $media): void
    {
        app()->call([new CustomSummaryJob($media, (string) $this->user->id), 'handle']);
    }

    public function testStoresAUserSpecificSummaryWithoutTouchingTheSharedOne(): void
    {
        $this->fakeCompletion();
        $this->givenAPaidUserWithABoundSource();
        $media = $this->givenACaptionedVideo();
        $shared = $media->summaries()->create(['user_id' => null, 'locale' => 'en', 'status' => Summary::STATUS_COMPLETED]);

        $this->runJob($media);

        $own = $media->summaries()->where('user_id', $this->user->id)->first();
        $this->assertNotNull($own);
        $this->assertSame(Summary::STATUS_COMPLETED, $own->status);
        $this->assertSame('One line.', $own->text['short_summary']);

        // 共用摘要與影片狀態都不能被動到——共用流程的狀態機跟這支 job 無關。
        $this->assertSame(Summary::STATUS_COMPLETED, $shared->fresh()->status);
        $this->assertSame(Media::STATUS_SUMMARIZED, $media->fresh()->status);
    }

    /**
     * 讀取端（Media::summaryFor）要挑得到剛寫進去的這份，而不是退回共用版本——
     * 寫得進去卻讀不出來，等於沒做。
     */
    public function testTheStoredSummaryIsWhatTheUserGetsBack(): void
    {
        $this->fakeCompletion();
        $this->givenAPaidUserWithABoundSource();
        $media = $this->givenACaptionedVideo();
        $media->summaries()->create([
            'user_id' => null,
            'locale'  => 'en',
            'status'  => Summary::STATUS_COMPLETED,
            'text'    => ['short_summary' => 'Shared.'],
        ]);

        $this->runJob($media);

        $this->assertSame('One line.', $media->summaryFor($this->user->fresh(), true)?->text['short_summary']);
    }

    /** 用的是提示詞的內容，而且兩個提示詞綁同一來源時取最近更新的那個。 */
    public function testUsesTheMostRecentlyUpdatedPrompt(): void
    {
        $this->fakeCompletion();
        $this->givenAPaidUserWithABoundSource();
        $newer = CustomPrompt::query()->create([
            'user_id' => $this->user->id,
            'title'   => 'Newer',
            'content' => 'NEWER PROMPT CONTENT',
        ]);
        $newer->sources()->attach($this->source->id);
        CustomPrompt::query()->whereKey($newer->id)->update(['updated_at' => now()->addMinute()]);

        $this->runJob($this->givenACaptionedVideo());

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'NEWER PROMPT CONTENT'));
    }

    /**
     * 明確送出生成參數，不吃全域預設的 2000 tokens。
     *
     * 實測預設的推理模型會把 2000 tokens 全部花在思考上，content 回 null，自訂摘要
     * 與試跑一律「沒有結果」。這條守著那兩個參數不被拿掉。
     */
    public function testSendsEnoughTokensAndLowReasoningEffort(): void
    {
        $this->fakeCompletion();
        $this->givenAPaidUserWithABoundSource();

        $this->runJob($this->givenACaptionedVideo());

        Http::assertSent(function ($request): bool {
            $body = json_decode((string) $request->body(), true);

            return ($body['max_tokens'] ?? 0) >= 8000
                && ($body['reasoning']['effort'] ?? null) === 'low';
        });
    }

    /** 派工之後方案降級：什麼都不寫，也不呼叫模型（不產生推論成本）。 */
    public function testDoesNothingOnceThePlanNoLongerAllowsIt(): void
    {
        $this->fakeCompletion();
        $this->givenAPaidUserWithABoundSource(customSummaryEnabled: false);
        $media = $this->givenACaptionedVideo();

        $this->runJob($media);

        $this->assertSame(0, $media->summaries()->where('user_id', $this->user->id)->count());
        Http::assertNothingSent();
    }

    /** 派工之後取消訂閱該來源：同上。 */
    public function testDoesNothingOnceTheUserUnfollowsTheSource(): void
    {
        $this->fakeCompletion();
        $this->givenAPaidUserWithABoundSource();
        $media = $this->givenACaptionedVideo();
        $this->user->sources()->detach($this->source->id);

        $this->runJob($media);

        $this->assertSame(0, $media->summaries()->where('user_id', $this->user->id)->count());
        Http::assertNothingSent();
    }

    /**
     * 最終失敗時標成 failed。讀取端只挑 completed，所以使用者看到的是共用摘要，
     * 而不是一個永遠「處理中」的空白。
     */
    public function testFailedHookMarksTheRowFailedSoReadersFallBackToTheSharedSummary(): void
    {
        $this->fakeCompletion('not json at all');
        $this->givenAPaidUserWithABoundSource();
        $media = $this->givenACaptionedVideo();

        $job = new CustomSummaryJob($media, (string) $this->user->id);
        app()->call([$job, 'handle']);
        $job->failed(null);

        $own = $media->summaries()->where('user_id', $this->user->id)->first();
        $this->assertSame(Summary::STATUS_FAILED, $own->status);
        $this->assertNull($media->summaryFor($this->user->fresh(), true));
    }
}
