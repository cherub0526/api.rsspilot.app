<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class ChatMessage extends Model
{
    use HasUlids;
    use HasFactory;

    public const string ROLE_USER = 'user';
    public const string ROLE_AI = 'ai';

    public const UPDATED_AT = null;

    public static array $roleMaps = [
        self::ROLE_USER => '使用者',
        self::ROLE_AI   => 'AI',
    ];

    public bool $timestamps = false;

    protected ?string $table = 'chat_messages';

    protected array $fillable = [
        'session_id',
        'role',
        'content',
        'created_at',
    ];

    protected array $casts = [
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'id');
    }
}
