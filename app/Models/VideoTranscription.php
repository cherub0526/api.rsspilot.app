<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class VideoTranscription extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    protected ?string $table = 'video_transcriptions';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'media_id',
        'url_info',
        'start_transcription',
        'transcription',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'media_id'            => 'string',
        'url_info'            => 'array',
        'start_transcription' => 'array',
        'transcription'       => 'array',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }
}
