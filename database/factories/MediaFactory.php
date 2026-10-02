<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use Hypervel\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type'         => fake()->randomElement(array_keys(Media::$typeMaps)),
            'resource_id'  => fake()->unique()->regexify('[a-zA-Z0-9_-]{11}'),
            'title'        => fake()->sentence(),
            'description'  => fake()->paragraph(),
            'duration'     => fake()->numberBetween(60, 3600),
            'thumbnail'    => fake()->imageUrl(),
            'published_at' => fake()->dateTimeThisYear(),
            // no_captions 不算進影片額度（Media::scopeCountsTowardQuota），隨機抽到它會讓
            // 額度相關的測試偶發失敗；需要這個狀態的測試自己指定。
            'status' => fake()->randomElement(
                array_values(array_diff(array_keys(Media::$statusMap), [Media::STATUS_NO_CAPTIONS]))
            ),
            'video_detail' => [],
            'audio_detail' => [],
        ];
    }
}
