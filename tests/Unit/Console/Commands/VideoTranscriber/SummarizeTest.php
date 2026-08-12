<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Caption;
use App\Models\Summary;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * @internal
 * @covers \App\Console\Commands\VideoTranscriber\Summarize
 */
class SummarizeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fake prod-config plus a summary stream that yields $markdown.
     */
    private function fakeStream(string $markdown): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/prod-config*' => Http::response(
                ['code' => 100000, 'data' => ['t' => 1, 'nonce' => 'n', 'sign' => 's', 'secret_key' => 'k', 'app_id' => 'a']],
                200
            ),
            'videotranscriber.ai/api/v1/summary/completions*' => Http::response(
                'data: {"summary_id": "abc"}' . "\n\n"
                . 'data: ' . json_encode(['message' => $markdown]) . "\n\n"
                . 'data: [DONE]' . "\n\n",
                200
            ),
        ]);
    }

    private function transcribedMediaWithCaption(string $locale = Caption::LOCAL_EN): Media
    {
        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        Caption::factory()->create([
            'media_id' => $media->id,
            'locale'   => $locale,
            'primary'  => true,
            'text'     => 'the transcript',
        ]);

        return $media;
    }

    public function testStoresTheSummaryAndMarksTheMediaSummarized(): void
    {
        $this->fakeStream('# Title');

        $media = $this->transcribedMediaWithCaption();

        $this->artisan('videotranscriber:summary')->run();

        $media->refresh();
        $this->assertSame(Media::STATUS_SUMMARIZED, $media->status);

        $summary = $media->summaries()->first();
        $this->assertSame(Caption::LOCAL_EN, $summary->locale);
        $this->assertSame(Summary::STATUS_COMPLETED, $summary->status);
        $this->assertSame(VideoTranscriberClient::SUMMARY_MODEL, $summary->ai_model);
        $this->assertSame('# Title', $summary->text['long_summary']['content']);
        $this->assertSame('', $summary->text['short_summary']);
    }

    public function testSendsTheSmartSummaryPromptWrappedAroundTheCaption(): void
    {
        $this->fakeStream('# Title');

        $this->transcribedMediaWithCaption();

        $this->artisan('videotranscriber:summary')->run();

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

    public function testLanguageOptionReachesThePrompt(): void
    {
        $this->fakeStream('# Title');

        $this->transcribedMediaWithCaption();

        $this->artisan('videotranscriber:summary', ['--language' => 'Traditional Chinese'])->run();

        Http::assertSent(fn ($request) => !str_contains($request->url(), '/summary/completions')
            || str_contains($request->data()['text'], 'written exclusively in Traditional Chinese,'));
    }

    public function testOnlyPicksUpTranscribedMedia(): void
    {
        $this->fakeStream('# Title');

        $transcribed = $this->transcribedMediaWithCaption();
        $other = Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:summary')->run();

        $this->assertSame(Media::STATUS_SUMMARIZED, $transcribed->refresh()->status);
        $this->assertSame(Media::STATUS_CREATED, $other->refresh()->status);
        $this->assertSame(0, $other->summaries()->count());
    }

    public function testIdOptionIgnoresTheMediaStatus(): void
    {
        $this->fakeStream('# Title');

        $media = Media::factory()->create(['status' => Media::STATUS_SUMMARIZE_FAILED]);
        Caption::factory()->create([
            'media_id' => $media->id,
            'locale'   => Caption::LOCAL_EN,
            'primary'  => true,
            'text'     => 'the transcript',
        ]);

        $this->artisan('videotranscriber:summary', ['--id' => $media->id])->run();

        $this->assertSame(Media::STATUS_SUMMARIZED, $media->refresh()->status);
    }

    public function testOverwritesTheExistingSummaryForTheSameLocale(): void
    {
        $this->fakeStream('# Fresh');

        $media = $this->transcribedMediaWithCaption();
        $existing = $media->summaries()->create([
            'locale' => Caption::LOCAL_EN,
            'status' => Summary::STATUS_COMPLETED,
            'text'   => ['short_summary' => 'stale', 'long_summary' => ['content' => '# Stale']],
        ]);

        $this->artisan('videotranscriber:summary')->run();

        $this->assertSame(1, $media->summaries()->count());
        $this->assertSame('# Fresh', $existing->refresh()->text['long_summary']['content']);
    }

    public function testMarksFailedWhenTheStreamCarriesNoSummary(): void
    {
        // What an occasional Cloudflare 502 looks like: a plain-text body with
        // no SSE frames, which parses down to nothing.
        Http::fake([
            'videotranscriber.ai/api/v1/prod-config*'         => Http::response(['code' => 100000, 'data' => []], 200),
            'videotranscriber.ai/api/v1/summary/completions*' => Http::response('error code: 502', 200),
        ]);

        $media = $this->transcribedMediaWithCaption();

        $this->artisan('videotranscriber:summary')->run();

        $this->assertSame(Media::STATUS_SUMMARIZE_FAILED, $media->refresh()->status);
        $this->assertSame(Summary::STATUS_FAILED, $media->summaries()->first()->status);
    }

    public function testMarksFailedWhenTheRequestThrows(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::failedConnection(),
        ]);

        $media = $this->transcribedMediaWithCaption();

        $this->artisan('videotranscriber:summary')->run();

        $this->assertSame(Media::STATUS_SUMMARIZE_FAILED, $media->refresh()->status);
    }

    public function testMarksFailedWhenThereIsNoPrimaryCaption(): void
    {
        $this->fakeStream('# Title');

        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $this->artisan('videotranscriber:summary')->run();

        $this->assertSame(Media::STATUS_SUMMARIZE_FAILED, $media->refresh()->status);
        $this->assertSame(0, $media->summaries()->count());
    }
}
