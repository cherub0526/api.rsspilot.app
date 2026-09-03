<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;

class SourceService
{
    public function __construct(
        private readonly YoutubeService $youtubeService
    ) {
    }

    /**
     * 以 type + external_id 取回既有來源，沒有才建立。
     *
     * 這兩個欄位在 sources 上是 unique index，所以同一個頻道／播放清單全站只有一列，
     * 不論它是被使用者訂閱進來的還是被單支影片帶進來的。
     */
    public function firstOrCreate(
        string $type,
        string $externalId,
        string $title,
        ?string $thumbnail = null
    ): Source {
        return Source::firstOrCreate(
            ['type' => $type, 'external_id' => $externalId],
            [
                'title'     => $title,
                'url'       => $this->buildRssUrl($type, $externalId),
                'thumbnail' => $thumbnail,
                'status'    => Source::STATUS_ACTIVE,
            ]
        );
    }

    /**
     * 以影片帶回來的頻道資訊取回／建立 youtube_channel 來源。
     *
     * 來源已經存在時完全不碰 YouTube Data API；只有真的要新建立才去補頻道縮圖，
     * 而且是 best-effort——配額用盡不該讓「新增影片」整個功能跟著失敗。
     */
    public function firstOrCreateYoutubeChannel(string $channelId, string $title): Source
    {
        $source = Source::query()
            ->where('type', Source::TYPE_YOUTUBE_CHANNEL)
            ->where('external_id', $channelId)
            ->first();

        if ($source !== null) {
            return $source;
        }

        return $this->firstOrCreate(
            Source::TYPE_YOUTUBE_CHANNEL,
            $channelId,
            $title,
            $this->youtubeService->getChannelThumbnail($channelId)
        );
    }

    public function buildRssUrl(string $type, string $externalId): string
    {
        if ($type === Source::TYPE_YOUTUBE_CHANNEL) {
            return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $externalId;
        }

        return 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $externalId;
    }
}
