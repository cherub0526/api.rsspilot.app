<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Media;

/**
 * 影片畫面截圖在 S3 上的定址與存取。
 *
 * 這裡沒有任何資料表。路徑 `media/{mediaId}/thumbnails/{秒數}.{sha256}.jpg` 完全由
 * (mediaId, second, checksum) 推導得出，所以「這張圖存過了嗎」問 S3 就有答案，
 * 不需要另一份索引去描述 S3 已經知道的事。連帶也沒有孤兒列要清、沒有 unique
 * index 要防並發。對話訊息裡因此只存秒數與 checksum，URL 於輸出當下才簽。
 *
 * **為什麼路徑裡要有 checksum。** 一秒有 24–60 幀，而 iframe API 只給浮點秒、
 * 拿不到 fps 也不能 seek 到幀，所以「同一秒」必然對應到很多張不同的畫面。早期
 * 版本只用秒數當 key，結果是硬切前後的兩張圖會互相頂替：使用者看著切換後的
 * 畫面、拿到的卻是切換前那張，AI 也跟著回答錯的內容。改成內容定址之後，不同
 * 的畫面就是不同的物件，不再互相覆蓋。
 *
 * 秒數留在路徑裡是為了可讀與可排序（人工翻 bucket 時看得出這張圖在影片的哪裡），
 * 但它不參與識別 —— 識別是 checksum 的事。
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

    /** 小寫十六進位的 SHA-256。前端用 crypto.subtle 算，後端一律重算驗證。 */
    public const string CHECKSUM_REGEX = '/^[0-9a-f]{64}$/';

    /**
     * 可定址的最大秒數。
     *
     * 這不只是「夠長」而已——GET 端點的路由 pattern 是 `{second:[0-9]{1,6}}`，
     * 超過 6 位的秒數寫得進 S3、卻永遠不會被那條路由匹配到，等於留下一個
     * 取不回來的物件。寫入端因此必須擋在同一個界線上，兩端才對得起來。
     * 改這個值要連 routes/v1.php 的 pattern 一起改。
     */
    public const int MAX_SECOND = 999999;

    /**
     * 秒數補零到 6 位。S3 console 與 listObjects 都是字典序，不補零的話
     * `1000.…` 會排在 `2.…` 前面；6 位足以容納 11 天長的影片。
     * 同一秒的多張畫面因此也會排在一起。
     */
    private const int SECOND_PAD = 6;

    private const int URL_TTL_HOURS = 24;

    public function __construct(private StorageService $storage)
    {
    }

    /** 這張截圖在 S3 上的 key。 */
    public function path(string $mediaId, int $second, string $checksum): string
    {
        return sprintf(
            'media/%s/thumbnails/%s.%s.%s',
            $mediaId,
            str_pad((string) $second, self::SECOND_PAD, '0', STR_PAD_LEFT),
            $checksum,
            self::EXTENSION
        );
    }

    public function exists(string $mediaId, int $second, string $checksum): bool
    {
        return $this->storage->exists($this->path($mediaId, $second, $checksum));
    }

    /**
     * 簽一組限時 URL。
     *
     * 物件本身維持 private，不走 app.cdn_url 那條公開路徑——web 端的畫面來源是
     * 使用者的分頁擷取，萬一裁切失準就會把我們自己的介面一起收進去，這種東西
     * 不該擺在猜得到的公開網址上。
     */
    public function url(string $mediaId, int $second, string $checksum): string
    {
        return $this->storage->getTemporaryUrl(
            $this->path($mediaId, $second, $checksum),
            now()->addHours(self::URL_TTL_HOURS)
        );
    }

    /**
     * 把本機檔案放上對應的位置。
     *
     * 呼叫端應先確認 exists() 為 false。內容定址之後覆寫本身是無害的（同一個
     * checksum 就是同一份 bytes），跳過只是省一次沒有意義的寫入。
     */
    public function put(string $mediaId, int $second, string $checksum, string $sourcePath): bool
    {
        return $this->storage->upload($sourcePath, $this->path($mediaId, $second, $checksum));
    }

    /**
     * 檔案內容的 sha256（小寫十六進位）。
     *
     * 前端送上來的 checksum 一律用這個重算比對，不直接採信：路徑由 checksum 決定，
     * 放任客戶端自己說等於讓它把任意內容擺到任意 key 上，內容定址的保證就沒了。
     */
    public function checksumOf(string $sourcePath): string
    {
        return hash_file('sha256', $sourcePath) ?: '';
    }

    /**
     * 秒數是否落在影片長度內。
     *
     * media.duration 預設是 0（尚未取得片長），此時不做上界判斷——把還沒抓到
     * 長度的影片一律擋掉，會讓剛加入的影片完全不能截圖。此時的上界由
     * MAX_SECOND 接手（在 ThumbnailValidator）。
     */
    public function isWithinDuration(Media $media, int $second): bool
    {
        $duration = (int) $media->getAttribute('duration');

        return $duration <= 0 || $second <= $duration;
    }
}
