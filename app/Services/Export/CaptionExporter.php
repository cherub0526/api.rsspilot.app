<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * 字幕匯出：把 captions.segments 轉成可下載的字幕檔。
 *
 * 產檔本來是在前端做的（renderer 已經持有整份逐字稿）。移到後端是因為下載是
 * 付費權益：留在前端的話，那道閘門只是一個改掉就沒了的 JS 判斷。
 */
class CaptionExporter
{
    public const FORMAT_SRT = 'srt';

    public const FORMAT_VTT = 'vtt';

    public const FORMAT_TXT = 'txt';

    public const FORMATS = [self::FORMAT_SRT, self::FORMAT_VTT, self::FORMAT_TXT];

    private const MIMES = [
        self::FORMAT_SRT => 'application/x-subrip',
        self::FORMAT_VTT => 'text/vtt',
        self::FORMAT_TXT => 'text/plain',
    ];

    public static function supports(string $format): bool
    {
        return in_array($format, self::FORMATS, true);
    }

    public static function mimeFor(string $format): string
    {
        return self::MIMES[$format] ?? 'text/plain';
    }

    /**
     * @param array<int, array{start?: mixed, end?: mixed, text?: mixed}> $segments
     */
    public function render(array $segments, string $format): string
    {
        $normalized = $this->normalize($segments);

        return match ($format) {
            self::FORMAT_SRT => $this->toSrt($normalized),
            self::FORMAT_VTT => $this->toVtt($normalized),
            default          => $this->toTxt($normalized),
        };
    }

    /**
     * 逐字稿偶爾會出現 end <= start（切段的時間戳抖動），這種 cue 播放器會直接
     * 丟掉。統一補成至少一秒，寧可字幕停久一點也不要整句消失。
     *
     * @param array<int, array{start?: mixed, end?: mixed, text?: mixed}> $segments
     * @return array<int, array{start: float, end: float, text: string}>
     */
    private function normalize(array $segments): array
    {
        $out = [];

        foreach ($segments as $segment) {
            $text = trim(strval($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $start = max(0.0, floatval($segment['start'] ?? 0));
            $end = floatval($segment['end'] ?? 0);

            $out[] = [
                'start' => $start,
                'end'   => $end > $start ? $end : $start + 1,
                'text'  => $text,
            ];
        }

        return $out;
    }

    /**
     * `HH:MM:SS<sep>mmm`。SRT 用逗號、WebVTT 用小數點分隔毫秒，其餘完全相同。
     *
     * 先四捨五入成整數毫秒再拆位。若先取整數秒、再單獨四捨五入小數部分，
     * 2.9996 會算出 1000 毫秒而輸出壞掉的 `00:00:02,1000`。
     */
    private function timestamp(float $seconds, string $msSeparator): string
    {
        $totalMs = (int) round(max(0.0, $seconds) * 1000);
        $ms = $totalMs % 1000;
        $whole = intdiv($totalMs - $ms, 1000);

        return sprintf(
            '%02d:%02d:%02d%s%03d',
            intdiv($whole, 3600),
            intdiv($whole % 3600, 60),
            $whole % 60,
            $msSeparator,
            $ms
        );
    }

    /**
     * SRT 慣例用 CRLF；舊的桌面播放器對 LF-only 的容忍度沒那麼一致。
     *
     * @param array<int, array{start: float, end: float, text: string}> $segments
     */
    private function toSrt(array $segments): string
    {
        $blocks = [];

        foreach ($segments as $i => $segment) {
            $blocks[] = implode("\r\n", [
                (string) ($i + 1),
                $this->timestamp($segment['start'], ',') . ' --> ' . $this->timestamp($segment['end'], ','),
                $segment['text'],
            ]);
        }

        return implode("\r\n\r\n", $blocks) . "\r\n";
    }

    /**
     * @param array<int, array{start: float, end: float, text: string}> $segments
     */
    private function toVtt(array $segments): string
    {
        $lines = ['WEBVTT', ''];

        foreach ($segments as $segment) {
            $lines[] = $this->timestamp($segment['start'], '.') . ' --> ' . $this->timestamp($segment['end'], '.');
            $lines[] = $segment['text'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * 純文字：只留內容，給要貼進文件或丟去別的工具的人用。
     *
     * @param array<int, array{start: float, end: float, text: string}> $segments
     */
    private function toTxt(array $segments): string
    {
        return implode("\n", array_column($segments, 'text')) . "\n";
    }
}
