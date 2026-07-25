<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use App\Models\VideoTranscription;
use Hypervel\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoTranscription>
 */
class VideoTranscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id'            => Media::factory(),
            'url_info'            => [],
            'start_transcription' => [],
            'transcription'       => null,
        ];
    }
}
