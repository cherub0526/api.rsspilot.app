<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;

class Config extends Model
{
    use HasFactory;

    public const KEY_VIDEOTRANSCRIBER = 'videotranscriber';

    /** class → OpenRouter 模型的對照表，見 App\Utils\AI\OpenRouterModels */
    public const KEY_OPENROUTER_MODELS = 'openrouter_models';

    protected ?string $table = 'configs';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'key',
        'value',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'value' => 'array',
    ];

    /**
     * Get the decoded value of a config key.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    /**
     * Create or update a config key with the given value.
     */
    public static function setValue(string $key, mixed $value): static
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
