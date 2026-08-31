<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use App\Models\ChatMessage;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Tests\TestCase;

/**
 * 訊息片段結構。功能（thinking / tool_call 的實際產生）尚未實作，
 * 這裡守的是結構本身：舊資料要能退回單一 text 片段，新片段型別要存得進去、讀得回來。
 *
 * @internal
 * @coversNothing
 */
class ChatMessagePartsTest extends TestCase
{
    use RefreshDatabase;

    private function makeMessage(array $attributes = []): ChatMessage
    {
        return ChatMessage::create(array_merge([
            'session_id' => '01JCXYZ123456789ABCDEFGHIJ',
            'role'       => ChatMessage::ROLE_AI,
            'content'    => '這部影片在講 AI 工作流程。',
            'created_at' => now(),
        ], $attributes));
    }

    public function testLegacyMessageWithoutPartsFallsBackToASingleTextPart(): void
    {
        // parts 欄位出現之前寫入的資料列：不做回填，讀取時由 contentParts() 補上。
        $message = $this->makeMessage();

        $this->assertNull($message->getAttribute('parts'));
        $this->assertSame(
            [['type' => ChatMessage::PART_TEXT, 'text' => '這部影片在講 AI 工作流程。']],
            $message->contentParts()
        );
    }

    public function testPartsRoundTripThroughTheJsonColumn(): void
    {
        $parts = [
            ['type' => ChatMessage::PART_THINKING, 'text' => '先查影片字幕再回答。'],
            [
                'type'  => ChatMessage::PART_TOOL_CALL,
                'id'    => 'call_1',
                'name'  => 'search_captions',
                'input' => ['query' => 'AI 工作流程'],
            ],
            [
                'type'         => ChatMessage::PART_TOOL_RESULT,
                'tool_call_id' => 'call_1',
                'output'       => '找到 3 段字幕。',
                'is_error'     => false,
            ],
            ['type' => ChatMessage::PART_TEXT, 'text' => '這部影片在講 AI 工作流程。'],
        ];

        $message = $this->makeMessage(['parts' => $parts]);

        $this->assertSame($parts, $message->fresh()->contentParts());
    }

    public function testPartsToTextConcatenatesOnlyTheTextParts(): void
    {
        $message = $this->makeMessage([
            'content' => '前段後段',
            'parts'   => [
                ['type' => ChatMessage::PART_THINKING, 'text' => '不該出現在投影裡'],
                ['type' => ChatMessage::PART_TEXT, 'text' => '前段'],
                [
                    'type'  => ChatMessage::PART_TOOL_CALL,
                    'id'    => 'call_1',
                    'name'  => 'noop',
                    'input' => [],
                ],
                ['type' => ChatMessage::PART_TEXT, 'text' => '後段'],
            ],
        ]);

        // content 是 text 片段的投影：思考過程與工具呼叫不進入送給模型的歷史。
        $this->assertSame('前段後段', $message->partsToText());
    }

    public function testJsonColumnKeepsChineseCharactersReadable(): void
    {
        // App\Models\Model::asJson() 覆寫過編碼旗標，中文不該變成 \uXXXX。
        $this->makeMessage(['parts' => [['type' => ChatMessage::PART_TEXT, 'text' => '中文']]]);

        // 走 query builder 而不是 model，才拿得到未經 cast 的原始字串。
        $raw = DB::table('chat_messages')->value('parts');

        $this->assertIsString($raw);
        $this->assertStringContainsString('中文', $raw);
    }
}
