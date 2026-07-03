<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Caption;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class GroqControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testStoreValidatesRequiredFields(): void
    {
        $media = Media::factory()->create();

        $this->json('POST', route('api.v1.webhook.groq.store', ['mediaId' => $media->id]), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['status', 'data']]);
    }

    public function testStoreValidatesSuccessDataFields(): void
    {
        $media = Media::factory()->create();

        $this->json('POST', route('api.v1.webhook.groq.store', ['mediaId' => $media->id]), ['status' => 'success', 'data' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['data.language', 'data.duration', 'data.text', 'data.words', 'data.segments']]);
    }

    public function testStoreValidatesErrorDataFields(): void
    {
        $media = Media::factory()->create();

        $this->json('POST', route('api.v1.webhook.groq.store', ['mediaId' => $media->id]), ['status' => 'error', 'data' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['data.error']]);
    }

    public function testStoreReturns404ForNonExistentMedia(): void
    {
        $params = [
            'status' => 'success',
            'data'   => [
                'language' => 'English',
                'duration' => 123.45,
                'text'     => 'Hello world.',
                'words'    => [['word' => 'Hello', 'start' => 0, 'end' => 0.5]],
                'segments' => [['start' => 0, 'end' => 1, 'text' => 'Hello world.']],
            ],
        ];

        $this->json('POST', route('api.v1.webhook.groq.store', ['mediaId' => '01jsvgt3prpypqwex4wj78bznk']), $params)
            ->assertStatus(404);
    }

    public function testStoreCreatesCaptionAndMarksTranscribedOnSuccess(): void
    {
        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);

        $params = [
            'status' => 'success',
            'data'   => [
                'language' => 'English',
                'duration' => 123.45,
                'text'     => 'Hello world.',
                'words'    => [['word' => 'Hello', 'start' => 0, 'end' => 0.5]],
                'segments' => [['start' => 0, 'end' => 1, 'text' => '  Hello world.  ']],
            ],
        ];

        $this->json('POST', route('api.v1.webhook.groq.store', ['mediaId' => $media->id]), $params)
            ->assertStatus(200);

        $caption = Caption::where('media_id', $media->id)->first();
        $this->assertNotNull($caption);
        $this->assertEquals('en', $caption->locale);
        $this->assertTrue((bool) $caption->primary);
        $this->assertEquals('Hello world.', $caption->text);
        $this->assertEquals('Hello world.', $caption->segments[0]['text']);

        $this->assertEquals(Media::STATUS_TRANSCRIBED, $media->fresh()->status);
    }

    public function testStoreMarksTranscribeFailedOnError(): void
    {
        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);

        $params = [
            'status' => 'error',
            'data'   => ['error' => 'Audio quality too low.'],
        ];

        $this->json('POST', route('api.v1.webhook.groq.store', ['mediaId' => $media->id]), $params)
            ->assertStatus(200);

        $this->assertEquals(Media::STATUS_TRANSCRIBE_FAILED, $media->fresh()->status);
        $this->assertEquals(0, Caption::where('media_id', $media->id)->count());
    }
}
