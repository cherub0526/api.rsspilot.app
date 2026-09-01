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

    /**
     * 訊息片段的型別。一則訊息由一個或多個片段依序組成，agent 的思考過程與
     * 工具呼叫都是片段，而不是另一則訊息——它們屬於同一個回合。
     */
    public const string PART_TEXT = 'text';

    public const string PART_THINKING = 'thinking';

    public const string PART_TOOL_CALL = 'tool_call';

    public const string PART_TOOL_RESULT = 'tool_result';

    public const UPDATED_AT = null;

    public static array $roleMaps = [
        self::ROLE_USER => '使用者',
        self::ROLE_AI   => 'AI',
    ];

    public static array $partTypeMaps = [
        self::PART_TEXT        => '文字',
        self::PART_THINKING    => '思考過程',
        self::PART_TOOL_CALL   => '工具呼叫',
        self::PART_TOOL_RESULT => '工具結果',
    ];

    public bool $timestamps = false;

    protected ?string $table = 'chat_messages';

    protected array $fillable = [
        'session_id',
        'role',
        'content',
        'parts',
        'created_at',
    ];

    protected array $casts = [
        'parts'      => 'array',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'id');
    }

    /**
     * 這則訊息的片段序列，永遠回傳陣列。
     *
     * parts 為 null 代表這列是在片段結構出現之前寫的，此時把 content 包成單一
     * text 片段回傳——呼叫端因此不需要分兩種情況處理，新舊資料長得一樣。
     *
     * @return array<int, array<string, mixed>>
     */
    public function contentParts(): array
    {
        $parts = $this->getAttribute('parts');

        if (is_array($parts) && $parts !== []) {
            return $parts;
        }

        return [[
            'type' => self::PART_TEXT,
            'text' => (string) $this->getAttribute('content'),
        ]];
    }

    /**
     * 片段序列攤平成純文字，也就是 content 欄位該有的值。
     *
     * content 不是重複資料而是 text 片段的投影：送給模型的對話歷史、列表頁的預覽
     * 都只要文字，讓它們去走 parts 只是把同一段串接邏輯抄到各處。
     */
    public function partsToText(): string
    {
        $texts = [];

        foreach ($this->contentParts() as $part) {
            if (($part['type'] ?? null) === self::PART_TEXT && isset($part['text'])) {
                $texts[] = (string) $part['text'];
            }
        }

        return implode('', $texts);
    }
}
