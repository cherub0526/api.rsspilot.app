<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 公開字幕閘門（`services.youtube.require_public_captions`，env `MEDIA_REQUIRE_PUBLIC_CAPTIONS`）。
 *
 * 開啟時，只有 YouTube 上已經有公開字幕的影片才會進入轉錄流程：
 * - 手動新增（MediaController::store）沒有字幕就回 422，不建立 media；
 * - 頻道／播放清單同步進來的，由 VideoTranscriberStartJob 檢查，沒有字幕就標成
 *   Media::STATUS_NO_CAPTIONS，不送轉錄。
 *
 * 檢查結果記在 `media.video_detail['public_captions']`，同一支影片只查一次——
 * captions.list 每次 50 單位，預設每日配額 10,000 單位只夠查約 200 支。
 *
 * 這只是「收不收」的閘門：判斷用的是官方 Data API，但字幕內容本身官方 API 拿不到
 * （captions.download 只限影片擁有者），轉錄仍走既有流程。
 */
class PublicCaptionGate
{
    public function __construct(protected YoutubeService $youtube)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('services.youtube.require_public_captions', false);
    }

    /**
     * true＝有公開字幕；false＝確定沒有；null＝查不到（配額用盡、API 錯誤），晚點再查。
     */
    public function hasPublicCaptions(string $videoId): ?bool
    {
        return $this->youtube->hasCaptionTracks($videoId);
    }
}
