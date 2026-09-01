<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Caption extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    public const LOCAL_ZH_TW = 'zh_tw';

    public const LOCAL_EN = 'en';

    public static array $localeMaps = [
        self::LOCAL_ZH_TW => '繁體中文',
        self::LOCAL_EN    => '英文',
    ];

    public static array $groqMaps = [
        'English' => self::LOCAL_EN,
    ];

    protected ?string $table = 'captions';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'media_id',
        'locale',
        'primary',
        'text',
        'segments',
        'word_segments',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'media_id'      => 'string',
        'locale'        => 'string',
        'text'          => 'string',
        'segments'      => 'array',
        'word_segments' => 'array',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }

    /**
     * 逐字稿的帶時間戳版本，每塊一行：.
     *
     * ```
     * [00:00:00 ~ 00:00:34] 第一句\n第二句
     * [00:00:34 ~ 00:01:07] 第三句\n第四句
     * ```
     *
     * `text` 欄位是把所有 segment 用空白接成的一整條字串，時間資訊只留在
     * `segments`（三個寫入端 GroqController、YoutubeCaptionJob、
     * VideoTranscriberFetchJob 都保證有 start / end / text，其餘欄位各家不同，
     * 這裡只讀共通的三個）。要讓 AI 有辦法引用時間點就得走這裡而不是 `text`。
     *
     * 為什麼要合併成塊：單一 segment 只有 1~4 秒，逐段一行會讓同一份逐字稿膨脹
     * 到近三倍長度（實測 19,731 字 → 55,361 字），成本直接反映在每次摘要上。
     * 30 秒一塊約只多兩成。
     *
     * 沒有 speaker：三個寫入端都沒有存說話人，這裡也就不編造——固定寫死一個
     * 說話人反而會讓摘要把訪談、對話類影片的發言歸錯人。
     *
     * @param int $blockSeconds 一塊最短涵蓋的秒數，超過就換下一塊
     * @return string 沒有可用的 segments 時回傳空字串，呼叫端自行決定要不要退回 `text`
     */
    public function timestampedTranscript(int $blockSeconds = 30): string
    {
        $lines = [];
        $texts = [];
        $start = null;
        $end = 0.0;

        foreach ((array) $this->getAttribute('segments') as $segment) {
            $content = trim((string) ($segment['text'] ?? ''));

            if ($content === '') {
                continue;
            }

            $start ??= (float) ($segment['start'] ?? 0);
            $end = (float) ($segment['end'] ?? 0);
            $texts[] = $content;

            if ($end - $start >= $blockSeconds) {
                $lines[] = $this->transcriptBlock($start, $end, $texts);
                $texts = [];
                $start = null;
            }
        }

        if ($texts !== []) {
            $lines[] = $this->transcriptBlock((float) $start, $end, $texts);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $texts
     */
    private function transcriptBlock(float $start, float $end, array $texts): string
    {
        return sprintf(
            '[%s ~ %s] %s',
            $this->formatTimestamp($start),
            $this->formatTimestamp($end),
            implode("\n", $texts)
        );
    }

    /**
     * 秒數轉 [hh:mm:ss]，與摘要模板允許的時間戳格式一致。
     */
    private function formatTimestamp(float $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
