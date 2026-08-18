<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Summary;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class SummariesControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validParams(): array
    {
        return [
            'locale' => 'en',
            'text'   => [
                'short_summary' => 'A brief summary.',
                'long_summary'  => [
                    'content'    => 'A detailed summary.',
                    'key_points' => ['Point one', 'Point two'],
                    'keywords'   => ['keyword1', 'keyword2'],
                ],
            ],
        ];
    }

    public function testStoreValidatesRequiredFields(): void
    {
        $media = Media::factory()->create();

        $this->json('POST', route('api.v1.webhook.summaries.store', ['mediaId' => $media->id]), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['locale', 'text']]);
    }

    public function testStoreValidatesNestedTextStructure(): void
    {
        $media = Media::factory()->create();

        $params = ['locale' => 'en', 'text' => ['short_summary' => 'A brief summary.']];

        $this->json('POST', route('api.v1.webhook.summaries.store', ['mediaId' => $media->id]), $params)
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['text.long_summary']]);
    }

    public function testStoreReturns404ForNonExistentMedia(): void
    {
        $this->json('POST', route('api.v1.webhook.summaries.store', ['mediaId' => '01jsvgt3prpypqwex4wj78bznk']), $this->validParams())
            ->assertStatus(404);
    }

    public function testStoreCreatesSummaryAndMarksMediaSummarized(): void
    {
        $media = Media::factory()->create(['status' => Media::STATUS_SUMMARIZING]);

        $this->json('POST', route('api.v1.webhook.summaries.store', ['mediaId' => $media->id]), $this->validParams())
            ->assertStatus(200);

        $summary = Summary::where('media_id', $media->id)->where('locale', 'en')->first();
        $this->assertNotNull($summary);
        $this->assertEquals($this->validParams()['text'], $summary->text);

        // 讀取端只認 completed 的摘要，status 沒跟著寫的話這份摘要等於不存在。
        $this->assertEquals(Summary::STATUS_COMPLETED, $summary->status);

        $this->assertEquals(Media::STATUS_SUMMARIZED, $media->fresh()->status);
    }

    public function testStoreUpdatesExistingSummaryForSameLocale(): void
    {
        $media = Media::factory()->create();
        Summary::factory()->create(['media_id' => $media->id, 'locale' => 'en', 'text' => ['old' => 'data']]);

        $this->json('POST', route('api.v1.webhook.summaries.store', ['mediaId' => $media->id]), $this->validParams())
            ->assertStatus(200);

        $this->assertEquals(1, Summary::where('media_id', $media->id)->where('locale', 'en')->count());

        $summary = Summary::where('media_id', $media->id)->where('locale', 'en')->first();
        $this->assertEquals($this->validParams()['text'], $summary->text);
        $this->assertEquals(Summary::STATUS_COMPLETED, $summary->status);
    }
}
