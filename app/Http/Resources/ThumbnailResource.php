<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

/**
 * 一張影片畫面截圖。
 *
 * 沒有 id——這個資源沒有資料表，(media_id, second, checksum) 就是它的身分，而真正
 * 識別內容的是 checksum：同一秒可以有很多張不同的畫面。url 是當下簽出的限時連結，
 * 不該被呼叫端存下來重用；對話歷史存的是 second 與 checksum，每次輸出重簽。
 *
 * @property array{media_id: string, second: int, checksum: string, url: string} $resource
 */
class ThumbnailResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [
            'media_id' => strval($this->resource['media_id']),
            'second'   => intval($this->resource['second']),
            'checksum' => strval($this->resource['checksum']),
            'url'      => strval($this->resource['url']),
        ];
    }
}
