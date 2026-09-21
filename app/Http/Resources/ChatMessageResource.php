<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\ThumbnailService;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class ChatMessageResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * 建立集合，並先把來源 session 掛回每一則訊息。
     *
     * image 片段要靠 session.media_id 才簽得出 URL，而關聯是反指回來源本身的——
     * 讓每則訊息各自去查會變成 N+1。呼叫端手上本來就有 session，直接塞回去。
     *
     * @param null|iterable<int, ChatMessage> $messages 預設取 session 已載入的全部訊息
     */
    public static function forSession(ChatSession $session, ?iterable $messages = null): AnonymousResourceCollection
    {
        $items = $messages ?? $session->getAttribute('messages');

        foreach ($items as $message) {
            $message->setRelation('session', $session);
        }

        return static::collection($items);
    }

    public function toArray(): array
    {
        return [
            'id'      => strval($this->resource->id),
            'role'    => strval($this->resource->getAttribute('role')),
            'content' => strval($this->resource->getAttribute('content')),
            // parts 是結構化的真相，content 是它的純文字投影。兩者都輸出：
            // 既有呼叫端讀 content 不受影響，新的呼叫端讀 parts 才拿得到
            // 思考過程與工具呼叫。parts 對舊資料列也一定有值（見 contentParts）。
            'parts'      => $this->withImageUrls($this->resource->contentParts()),
            'created_at' => $this->resource->getAttribute('created_at')?->toIso8601String(),
        ];
    }

    /**
     * 幫 image 片段補上當下簽出的 URL。
     *
     * 片段本身只存秒數與 checksum，簽章是有效期限 24 小時的東西——存進 parts 的話，隔天回頭
     * 看同一段對話就是一排破圖。代價是每次輸出都要簽一次，但簽章是本機運算，
     * 不會多打一次 S3。
     *
     * @param array<int, array<string, mixed>> $parts
     * @return array<int, array<string, mixed>>
     */
    private function withImageUrls(array $parts): array
    {
        $mediaId = null;

        foreach ($parts as $index => $part) {
            if (($part['type'] ?? null) !== ChatMessage::PART_IMAGE) {
                continue;
            }

            if (!isset($part['second'], $part['checksum'])) {
                continue;
            }

            // 只有真的出現 image 片段才去碰 session 關聯——AI 的回覆永遠不帶圖，
            // 無條件取用等於讓每一則訊息都多一次查詢。
            $mediaId ??= $this->resource->session?->getAttribute('media_id');

            if ($mediaId === null) {
                continue;
            }

            $parts[$index]['url'] = app(ThumbnailService::class)->url(
                (string) $mediaId,
                (int) $part['second'],
                (string) $part['checksum']
            );
        }

        return $parts;
    }
}
