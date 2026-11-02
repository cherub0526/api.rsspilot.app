<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SourceSkill;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Media;
use App\Models\Source;
use App\Models\Summary;
use App\Services\SourceSkill\SummarySampler;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * qa 的 SourceSkillSamplingTest 經由 generateRaw() 驗證送出的 prompt；這裡驗取材本身。
 *
 * @internal
 * @coversNothing
 */
class SummarySamplerTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL]);
    }

    private function video(?string $publishedAt): Media
    {
        return Media::factory()->create(['source_id' => $this->source->id, 'published_at' => $publishedAt]);
    }

    private function summary(Media $media, array $attributes = []): Summary
    {
        return $media->summaries()->create(array_merge([
            'user_id' => null,
            'locale'  => 'en',
            'status'  => Summary::STATUS_COMPLETED,
            'text'    => ['long_summary' => ['content' => 'Body', 'key_points' => [], 'keywords' => []]],
        ], $attributes));
    }

    /** @return list<string> */
    private function mediaIds(string $language = 'en', int $limit = 30): array
    {
        return array_map(
            fn (array $sample) => $sample['media']->id,
            (new SummarySampler())->sample($this->source, $language, $limit)
        );
    }

    public function testNewestFirstWithUnpublishedVideosLast(): void
    {
        $undated = $this->video(null);
        $old = $this->video('2026-01-01 00:00:00');
        $new = $this->video('2026-02-01 00:00:00');
        foreach ([$undated, $old, $new] as $media) {
            $this->summary($media);
        }

        $this->assertSame([$new->id, $old->id, $undated->id], $this->mediaIds());
    }

    public function testLimitCountsOnlyVideosWithAUsableSharedSummary(): void
    {
        $usable = $this->video('2026-01-01 00:00:00');
        $this->summary($usable);
        $empty = $this->video('2026-02-01 00:00:00');
        $this->summary($empty, ['text' => ['short_summary' => 'only short']]);
        $failed = $this->video('2026-03-01 00:00:00');
        $this->summary($failed, ['status' => Summary::STATUS_FAILED]);

        $this->assertSame([$usable->id], $this->mediaIds('en', 1));
    }

    public function testFallsBackToTheNewestUsableSummaryInAnotherLanguage(): void
    {
        $media = $this->video('2026-01-01 00:00:00');
        $older = $this->summary($media, ['locale' => 'ja']);
        $older->forceFill(['created_at' => Carbon::parse('2026-05-01')])->save();
        $newer = $this->summary($media, ['locale' => 'zh-TW']);
        $newer->forceFill(['created_at' => Carbon::parse('2026-06-01')])->save();
        $blankInEnglish = $this->summary($media, ['locale' => 'en', 'text' => []]);

        $samples = (new SummarySampler())->sample($this->source, 'en', 30);

        $this->assertCount(1, $samples);
        $this->assertSame($newer->id, $samples[0]['summary']->id);
        $this->assertNotSame($blankInEnglish->id, $samples[0]['summary']->id);
    }

    /** qa T03 r1：早期摘要存成 `zh_tw`，正規化後才比對；裸 `zh` 分不出繁簡，不算命中。 */
    public function testLocalesAreNormalisedAndBareChineseIsNotAMatch(): void
    {
        $media = $this->video('2026-01-01 00:00:00');
        $legacy = $this->summary($media, ['locale' => 'zh_tw']);
        $legacy->forceFill(['created_at' => Carbon::parse('2026-05-01')])->save();
        $bare = $this->summary($media, ['locale' => 'zh']);
        $bare->forceFill(['created_at' => Carbon::parse('2026-06-01')])->save();

        $samples = (new SummarySampler())->sample($this->source, 'zh-TW', 30);

        $this->assertSame($legacy->id, $samples[0]['summary']->id);
    }
}
