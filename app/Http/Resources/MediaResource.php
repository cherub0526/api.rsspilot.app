<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Hypervel\Support\Facades\Auth;
use Hypervel\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id'            => strval($this->resource->id),
            'url'           => 'https://www.youtube.com/embed/' . ($this->resource->video_detail['yt:videoId'] ?? ''),
            'type'          => strval($this->resource->type),
            'title'         => strval($this->resource->title),
            'description'   => strval($this->resource->description),
            'thumbnail'     => strval($this->resource->thumbnail),
            'published_at'  => strval($this->resource->published_at),
            'short_summary' => $this->shortSummary(),
            'source'        => $this->whenLoaded('source', fn () => new SourceResource($this->resource->source)),
        ];
    }

    /**
     * 與 /summaries、chat 取同一份摘要（見 Media::summaryFor()），只取已完成的。
     *
     * 原本是 `$media->summary ? $media->summary->text['short_summary'] : ''`：
     * 三元只判斷有沒有資料列、不判斷 `text`，撈到剛建立還沒填內容的那筆就是對
     * null 取索引——在這個專案是 500 而不是空字串。改用 `?->` 加 `??` 之後，
     * 缺 `text` 或缺 `short_summary` 都退成空字串。
     *
     * 這裡取當前登入者而不是靠關聯：摘要現在分「使用者自己的」與「全站共用的」，
     * 沒有使用者就無從選起，此時回空字串（列表類端點都掛 auth，實務上不會發生）。
     */
    private function shortSummary(): string
    {
        $user = Auth::guard('jwt')->user();

        if (!$user instanceof User) {
            return '';
        }

        return (string) ($this->resource->summaryFor($user, true)?->text['short_summary'] ?? '');
    }
}
