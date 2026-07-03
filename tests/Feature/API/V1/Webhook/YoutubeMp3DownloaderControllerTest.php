<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class YoutubeMp3DownloaderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testStoreValidatesRequiredFields(): void
    {
        $media = Media::factory()->create();

        $this->json('POST', route('api.v1.webhook.youtube-mp3-downloader.store', ['mediaId' => $media->id]), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['status', 'data.status', 'data.link']]);
    }

    public function testStoreValidatesStatusEnum(): void
    {
        $media = Media::factory()->create();

        $params = ['status' => 'unknown', 'data' => ['status' => 'ok', 'link' => 'https://example.com/audio.mp3']];

        $this->json('POST', route('api.v1.webhook.youtube-mp3-downloader.store', ['mediaId' => $media->id]), $params)
            ->assertStatus(422)
            ->assertJsonPath('messages.status.0', __('validators.youtube_mp3_downloader.status.in'));
    }

    public function testStoreReturns404ForNonExistentMedia(): void
    {
        $params = ['status' => 'success', 'data' => ['status' => 'ok', 'link' => 'https://example.com/audio.mp3']];

        $this->json('POST', route('api.v1.webhook.youtube-mp3-downloader.store', ['mediaId' => '01jsvgt3prpypqwex4wj78bznk']), $params)
            ->assertStatus(404);
    }

    public function testStoreUpdatesMediaAudioDetailAndStatus(): void
    {
        $media = Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $params = ['status' => 'success', 'data' => ['status' => 'ok', 'link' => 'https://example.com/audio.mp3']];

        $this->json('POST', route('api.v1.webhook.youtube-mp3-downloader.store', ['mediaId' => $media->id]), $params)
            ->assertStatus(200);

        $fresh = $media->fresh();
        $this->assertEquals(Media::STATUS_PROGRESS, $fresh->status);
        $this->assertEquals(['status' => 'ok', 'link' => 'https://example.com/audio.mp3'], $fresh->audio_detail);
    }
}
