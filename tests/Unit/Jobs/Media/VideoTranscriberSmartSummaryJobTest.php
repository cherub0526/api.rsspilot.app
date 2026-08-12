<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\Media;
use RuntimeException;
use App\Models\Caption;
use App\Models\Summary;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Jobs\Media\VideoTranscriberSmartSummaryJob;
use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * @internal
 * @covers \App\Jobs\Media\VideoTranscriberSmartSummaryJob
 */
class VideoTranscriberSmartSummaryJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fake prod-config plus a summary stream that yields $body verbatim.
     */
    private function fakeStream(string $body): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/prod-config*' => Http::response(
                ['code' => 100000, 'data' => ['t' => 1, 'nonce' => 'n', 'sign' => 's', 'secret_key' => 'k', 'app_id' => 'a']],
                200
            ),
            'videotranscriber.ai/api/v1/summary/completions*' => Http::response(
                'data: {"summary_id": "abc"}' . "\n\n"
                . 'data: ' . json_encode(['message' => $body]) . "\n\n"
                . 'data: [DONE]' . "\n\n",
                200
            ),
        ]);
    }

    /**
     * The JSON body the prompt asks the model for.
     */
    private function summaryJson(string $content, string $short = 'the short one'): string
    {
        return (string) json_encode([
            'short_summary' => $short,
            'long_summary'  => [
                'content'    => $content,
                'key_points' => ['point one', 'point two'],
                'keywords'   => ['alpha', 'beta'],
            ],
        ]);
    }

    private function transcribedMediaWithCaption(): Media
    {
        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        Caption::factory()->create([
            'media_id' => $media->id,
            'locale'   => Caption::LOCAL_EN,
            'primary'  => true,
            'text'     => 'the transcript',
        ]);

        return $media;
    }

    public function testStoresTheSummaryAndMarksTheMediaSummarized(): void
    {
        $this->fakeStream($this->summaryJson('# Title'));

        $media = $this->transcribedMediaWithCaption();

        (new VideoTranscriberSmartSummaryJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_SUMMARIZED, $media->status);

        $summary = $media->summaries()->first();
        $this->assertSame(Caption::LOCAL_EN, $summary->locale);
        $this->assertSame(Summary::STATUS_COMPLETED, $summary->status);
        $this->assertSame(VideoTranscriberClient::SUMMARY_MODEL, $summary->ai_model);
        $this->assertSame('the short one', $summary->text['short_summary']);
        $this->assertSame('# Title', $summary->text['long_summary']['content']);
        $this->assertSame(['point one', 'point two'], $summary->text['long_summary']['key_points']);
        $this->assertSame(['alpha', 'beta'], $summary->text['long_summary']['keywords']);
    }

    public function testAcceptsJsonThatArrivesWrappedInACodeFence(): void
    {
        // The prompt forbids fences, but models add them anyway; discarding an
        // otherwise-good summary over one would be the wrong trade.
        $this->fakeStream("```json\n" . $this->summaryJson('# Fenced') . "\n```");

        $media = $this->transcribedMediaWithCaption();

        (new VideoTranscriberSmartSummaryJob($media))->handle(new VideoTranscriberClient());

        $this->assertSame(Media::STATUS_SUMMARIZED, $media->refresh()->status);
        $this->assertSame('# Fenced', $media->summaries()->first()->text['long_summary']['content']);
    }

    public function testFillsInTheOptionalArraysWhenTheModelOmitsThem(): void
    {
        $this->fakeStream((string) json_encode(['long_summary' => ['content' => '# Bare']]));

        $media = $this->transcribedMediaWithCaption();

        (new VideoTranscriberSmartSummaryJob($media))->handle(new VideoTranscriberClient());

        $text = $media->summaries()->first()->text;
        $this->assertSame('# Bare', $text['long_summary']['content']);
        $this->assertSame('', $text['short_summary']);
        $this->assertSame([], $text['long_summary']['key_points']);
        $this->assertSame([], $text['long_summary']['keywords']);
    }

    public function testReleasesForRetryWhenTheResponseIsNotTheRequestedJson(): void
    {
        // Plain Markdown instead of the JSON contract — retryable, because a
        // model ignoring the output format usually complies on another go.
        $this->fakeStream('# Just markdown, no JSON');

        $media = $this->transcribedMediaWithCaption();

        $job = new VideoTranscriberSmartSummaryJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle(new VideoTranscriberClient());

        $this->assertTrue($job->job->isReleased());
        $this->assertNull($media->summaries()->first()->text);
    }

    public function testSendsTheSmartSummaryPromptWrappedAroundTheCaption(): void
    {
        $this->fakeStream($this->summaryJson('# Title'));

        (new VideoTranscriberSmartSummaryJob($this->transcribedMediaWithCaption()))
            ->handle(new VideoTranscriberClient());

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/summary/completions')) {
                return true;
            }

            $text = $request->data()['text'];

            return str_contains($text, 'You are an expert in summarizing transcript content')
                && str_contains($text, "Transcript Content:\nthe transcript")
                && str_contains($text, 'written exclusively in English,');
        });
    }

    public function testTheLanguageArgumentReachesThePrompt(): void
    {
        $this->fakeStream($this->summaryJson('# Title'));

        (new VideoTranscriberSmartSummaryJob($this->transcribedMediaWithCaption(), 'Traditional Chinese'))
            ->handle(new VideoTranscriberClient());

        Http::assertSent(fn ($request) => !str_contains($request->url(), '/summary/completions')
            || str_contains($request->data()['text'], 'written exclusively in Traditional Chinese,'));
    }

    public function testOverwritesTheExistingSummaryForTheSameLocale(): void
    {
        $this->fakeStream($this->summaryJson('# Fresh'));

        $media = $this->transcribedMediaWithCaption();
        $existing = $media->summaries()->create([
            'locale' => Caption::LOCAL_EN,
            'status' => Summary::STATUS_COMPLETED,
            'text'   => ['short_summary' => 'stale', 'long_summary' => ['content' => '# Stale']],
        ]);

        (new VideoTranscriberSmartSummaryJob($media))->handle(new VideoTranscriberClient());

        $this->assertSame(1, $media->summaries()->count());
        $this->assertSame('# Fresh', $existing->refresh()->text['long_summary']['content']);
    }

    public function testReleasesForRetryWhenTheStreamCarriesNoSummary(): void
    {
        // What an occasional Cloudflare 502 looks like: a plain-text body with
        // no SSE frames, which parses down to nothing. These clear by
        // themselves, so the job backs off instead of giving up.
        Http::fake([
            'videotranscriber.ai/api/v1/prod-config*'         => Http::response(['code' => 100000, 'data' => []], 200),
            'videotranscriber.ai/api/v1/summary/completions*' => Http::response('error code: 502', 200),
        ]);

        $media = $this->transcribedMediaWithCaption();

        $job = new VideoTranscriberSmartSummaryJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle(new VideoTranscriberClient());

        $this->assertTrue($job->job->isReleased());
        $this->assertSame(60, $job->job->releaseDelay);
        $this->assertSame(Media::STATUS_SUMMARIZING, $media->refresh()->status);
    }

    public function testMarksFailedOnceTheRetriesAreExhausted(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/prod-config*'         => Http::response(['code' => 100000, 'data' => []], 200),
            'videotranscriber.ai/api/v1/summary/completions*' => Http::response('error code: 502', 200),
        ]);

        $media = $this->transcribedMediaWithCaption();

        $job = new VideoTranscriberSmartSummaryJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 3;

        $job->handle(new VideoTranscriberClient());

        $this->assertFalse($job->job->isReleased());
        $this->assertSame(Media::STATUS_SUMMARIZE_FAILED, $media->refresh()->status);
        $this->assertSame(Summary::STATUS_FAILED, $media->summaries()->first()->status);
    }

    public function testBacksOffLongerWhenTheTokenCannotBeRefreshed(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/auth/email/login' => Http::response(
                ['code' => 100001, 'message' => 'invalid credentials'],
                200
            ),
            'videotranscriber.ai/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $media = $this->transcribedMediaWithCaption();

        $job = new VideoTranscriberSmartSummaryJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle(new VideoTranscriberClient());

        $this->assertTrue($job->job->isReleased());
        $this->assertSame(300, $job->job->releaseDelay);
    }

    public function testMarksFailedWhenThereIsNoPrimaryCaption(): void
    {
        $this->fakeStream($this->summaryJson('# Title'));

        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        (new VideoTranscriberSmartSummaryJob($media))->handle(new VideoTranscriberClient());

        $this->assertSame(Media::STATUS_SUMMARIZE_FAILED, $media->refresh()->status);
        $this->assertSame(0, $media->summaries()->count());
    }

    public function testFailedHookSettlesTheMediaWhenTheJobDiesOutsideHandle(): void
    {
        // Stands in for anything handle() cannot catch — a save the column
        // rejects, an Error, the DB dropping out. Without the hook the media
        // would sit on `summarizing` while the job is already in failed_jobs.
        $media = $this->transcribedMediaWithCaption();
        $media->fill(['status' => Media::STATUS_SUMMARIZING])->save();

        $summary = $media->summaries()->create([
            'locale' => Caption::LOCAL_EN,
            'status' => Summary::STATUS_CREATED,
        ]);

        (new VideoTranscriberSmartSummaryJob($media))->failed(new RuntimeException('boom'));

        $this->assertSame(Media::STATUS_SUMMARIZE_FAILED, $media->refresh()->status);
        $this->assertSame(Summary::STATUS_FAILED, $summary->refresh()->status);
    }

    public function testFailedHookStillSettlesTheMediaWithoutACaption(): void
    {
        $media = Media::factory()->create(['status' => Media::STATUS_SUMMARIZING]);

        (new VideoTranscriberSmartSummaryJob($media))->failed(new RuntimeException('boom'));

        $this->assertSame(Media::STATUS_SUMMARIZE_FAILED, $media->refresh()->status);
    }

    public function testUniqueIdIsScopedToTheMedia(): void
    {
        $media = $this->transcribedMediaWithCaption();

        $this->assertSame($media->id, (new VideoTranscriberSmartSummaryJob($media))->uniqueId());
    }
}
