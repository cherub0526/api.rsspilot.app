<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Media;

/**
 * 影片畫面截圖在 S3 上的定址與存取。
 *
 * 這裡沒有任何資料表。路徑 `media/{mediaId}/thumbnails/{秒數}.jpg` 完全由
 * (mediaId, second) 推導得出，所以「這一秒有沒有截過」問 S3 就有答案，不需要
 * 另一份索引去描述 S3 已經知道的事——連帶也沒有孤兒列要清、沒有 unique index
 * 要防並發。對話訊息裡因此只存秒數，URL 於輸出當下才簽。
 *
 * 同一個 mediaId 的畫面內容對所有使用者都一樣，所以截圖是跨使用者共用的：
 * 第二個人截同一秒時直接拿既有的圖，不必再上傳一次。
 */
class ThumbnailService
{
    /**
     * 副檔名同時是 S3 key 的一部分，改動等於讓既有的截圖全部失去命中。
     * 用 JPEG 而不是 PNG：1280px 的影片畫面存 PNG 約 1.5–3MB、JPEG 約 150KB，
     * 差一個數量級，而 vision 模型拿到 PNG 並不會看得更準。
     */
    public const string EXTENSION = 'jpg';

    public const string MIME_TYPE = 'image/jpeg';

    /**
     * 秒數補零到 6 位。S3 console 與 listObjects 都是字典序，不補零的話
     * `1000.jpg` 會排在 `2.jpg` 前面；6 位足以容納 11 天長的影片。
     */
    private const int SECOND_PAD = 6;

    private const int URL_TTL_HOURS = 24;

    public function __construct(private StorageService $storage)
    {
    }

    /** 這一秒的截圖在 S3 上的 key。 */
    public function path(string $mediaId, int $second): string
    {
        return sprintf(
            'media/%s/thumbnails/%s.%s',
            $mediaId,
            str_pad((string) $second, self::SECOND_PAD, '0', STR_PAD_LEFT),
            self::EXTENSION
        );
    }

    public function exists(string $mediaId, int $second): bool
    {
        return $this->storage->exists($this->path($mediaId, $second));
    }

    /**
     * 簽一組限時 URL。
     *
     * 物件本身維持 private，不走 app.cdn_url 那條公開路徑——web 端的畫面來源是
     * 使用者的分頁擷取，萬一裁切失準就會把我們自己的介面一起收進去，這種東西
     * 不該擺在猜得到的公開網址上。
     */
    public function url(string $mediaId, int $second): string
    {
        return $this->storage->getTemporaryUrl(
            $this->path($mediaId, $second),
            now()->addHours(self::URL_TTL_HOURS)
        );
    }

    /**
     * 把本機檔案放上該秒的位置。
     *
     * 呼叫端必須先確認 exists() 為 false：這裡是 first-write-wins，覆寫既有的圖
     * 等於讓後來的人可以替換掉所有人在那一秒看到的畫面。
     */
    public function put(string $mediaId, int $second, string $sourcePath): bool
    {
        return $this->storage->upload($sourcePath, $this->path($mediaId, $second));
    }

    /**
     * 秒數是否落在影片長度內。
     *
     * media.duration 預設是 0（尚未取得片長），此時不做上界判斷——把還沒抓到
     * 長度的影片一律擋掉，會讓剛加入的影片完全不能截圖。
     */
    public function isWithinDuration(Media $media, int $second): bool
    {
        $duration = (int) $media->getAttribute('duration');

        return $duration <= 0 || $second <= $duration;
    }
}
