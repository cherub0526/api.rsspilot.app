<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * 從中文文字判斷繁簡。
 *
 * 為什麼需要它：語音辨識回報的語言只到 `zh`，而專案的語系表分 `zh-TW` 與
 * `zh-CN`。上游能補上這個資訊的只有 YouTube 的 `defaultAudioLanguage`，但那是
 * 上傳者自己填的、有人不填也有人填錯，所以最後還是得有一個看文字本身說話的
 * 判斷。
 *
 * 判斷方式刻意只用「只存在於其中一套的字」。像「后」（繁體的皇后與簡體的後）
 * 或「里」（繁體的公里與簡體的裡）這種兩邊都合法的字一律不列，否則繁體文章會
 * 被自己的合法用字判成簡體。
 *
 * 侷限要講清楚：字幕的字形是**轉錄模型的輸出風格**，不必然等於說話者的地區。
 * 台灣華語被模型輸出成簡體時，這裡就會判成 `zh-CN`。所以它的定位是「其他來源
 * 都沒有結論時的仲裁」，不是第一順位。
 */
class ChineseVariant
{
    public const LOCALE_ZH_TW = 'zh-TW';

    public const LOCALE_ZH_CN = 'zh-CN';

    /**
     * 只看前面這麼多個字。字幕動輒上萬字，而繁簡風格在整份稿子裡是一致的，
     * 掃完全文不會讓結論更準，只會讓每支影片多花時間。
     */
    private const SAMPLE_LENGTH = 2000;

    private const TRADITIONAL_ONLY = '們這說會對時實點義華學過還讓與為東車見門問語關開國圖長馬鳥龍變愛兒從眾體傳親認識寫樣該請誰資邊遠連運進達發電頭現應無稱總當習經統議題師調驗';

    private const SIMPLIFIED_ONLY = '们这说会对时实点义华学过还让与为东车见门问语关开国图长马鸟龙变爱儿从众体传亲认识写样该请谁资边远连运进达发电头现应无称总当习经统议题师调验';

    /**
     * @return null|string `zh-TW` / `zh-CN`，兩邊一樣多（含完全沒有線索）時回 null
     */
    public static function detect(string $text): ?string
    {
        $sample = mb_substr($text, 0, self::SAMPLE_LENGTH);

        $traditional = self::countHits($sample, self::TRADITIONAL_ONLY);
        $simplified = self::countHits($sample, self::SIMPLIFIED_ONLY);

        if ($traditional === $simplified) {
            return null;
        }

        return $traditional > $simplified ? self::LOCALE_ZH_TW : self::LOCALE_ZH_CN;
    }

    /**
     * 逐字比對而不是 `str_contains` 逐個查找：後者要掃過整段文字 N 次，而且
     * 只知道「有沒有出現」，分不出出現一次與出現五十次。命中次數才是這裡的訊號。
     */
    private static function countHits(string $sample, string $charset): int
    {
        $lookup = array_flip(preg_split('//u', $charset, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $hits = 0;

        foreach (preg_split('//u', $sample, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (isset($lookup[$char])) {
                ++$hits;
            }
        }

        return $hits;
    }
}
