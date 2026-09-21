<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * 摘要匯出：把 summaries.text 組成可下載的文件。
 *
 * 匯出整份摘要（重點摘要 / 內文 / 重點整理 / 關鍵字），而不是播放頁畫面上那
 * 幾塊——面板為了版面只渲染其中一部分，但使用者要帶走的是全部。
 *
 * 段落標題走 __()，語系由 SetLocale middleware 依使用者設定決定。
 */
class SummaryExporter
{
    public const FORMAT_MD = 'md';

    public const FORMAT_TXT = 'txt';

    public const FORMATS = [self::FORMAT_MD, self::FORMAT_TXT];

    private const MIMES = [
        self::FORMAT_MD  => 'text/markdown',
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
     * @param array<string, mixed> $text summaries.text 原封不動
     */
    public function render(array $text, string $title, ?string $url, string $format): string
    {
        $md = $format === self::FORMAT_MD;
        $long = is_array($text['long_summary'] ?? null) ? $text['long_summary'] : [];

        $blocks = [$md ? '# ' . $title : $title];

        $url = trim(strval($url ?? ''));

        if ($url !== '') {
            // 冒號一律用半形加空白：這裡是三種語系共用的組字，全形「：」在英文檔裡會很突兀。
            $label = __('exports.summary.source');
            $blocks[] = $md ? "> {$label}: <{$url}>" : "{$label}: {$url}";
        }

        $short = trim(strval($text['short_summary'] ?? ''));

        if ($short !== '') {
            $blocks[] = $this->heading(__('exports.summary.short_summary'), $md);
            $blocks[] = $this->body($short, $md);
        }

        $content = trim(strval($long['content'] ?? ''));

        if ($content !== '') {
            $blocks[] = $this->heading(__('exports.summary.summary'), $md);
            $blocks[] = $this->body($content, $md);
        }

        $points = $this->nonEmpty($long['key_points'] ?? []);

        if ($points !== []) {
            $blocks[] = $this->heading(__('exports.summary.key_points'), $md);
            $blocks[] = implode("\n", array_map(fn (string $p) => '- ' . $this->body($p, $md), $points));
        }

        $keywords = $this->nonEmpty($long['keywords'] ?? []);

        if ($keywords !== []) {
            $blocks[] = $this->heading(__('exports.summary.keywords'), $md);
            $blocks[] = implode(', ', $keywords);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private function nonEmpty($values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v) => trim(strval($v)), $values),
            fn (string $v) => $v !== ''
        ));
    }

    private function heading(string $text, bool $md): string
    {
        return $md ? '## ' . $text : $text;
    }

    private function body(string $text, bool $md): string
    {
        return $md ? $this->demoteHeadings($text) : $this->stripMarkdown($text);
    }

    /**
     * 摘要內文自己就用 `##` 當小節標題，直接接在我們的「## 摘要」下面會變成同級，
     * 匯出的 Markdown 讀起來就沒有層次。整份內文的標題降一級再放進去。
     * 圍籬程式碼區塊裡的 `#` 不是標題，要跳過。
     */
    private function demoteHeadings(string $source): string
    {
        $inFence = false;
        $out = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $source)) as $line) {
            if (preg_match('/^[ \t]{0,3}```/', $line) === 1) {
                $inFence = !$inFence;
                $out[] = $line;

                continue;
            }

            // 已經是 h6 就不再降，多一個 # 只會變成不是標題的一行字
            $out[] = $inFence ? $line : preg_replace('/^([ \t]{0,3})(#{1,5})([ \t]+)/', '$1#$2$3', $line);
        }

        return implode("\n", $out);
    }

    /**
     * 把摘要內文的 Markdown 還原成純文字。
     *
     * 刻意只處理 renderer 的 lib/markdown.ts 認得的那組語法：摘要內文是同一個
     * 管線產出的，對付完整 CommonMark 只會多出用不到的分支。清單符號保留
     *（`- ` 在純文字裡本來就讀得懂），連結只留文字、丟掉網址，免得句子被長網址切斷。
     *
     * 行首縮排一律用 [ \t] 而不是 \s：多行模式的 ^ 會落在空行開頭，
     * \s 能吃掉後面那個換行，結果把段落之間的空行併掉。
     */
    private function stripMarkdown(string $source): string
    {
        $patterns = [
            '/^```.*$/m'                               => '',
            '/^[ \t]{0,3}#{1,6}[ \t]+/m'               => '',
            '/^[ \t]{0,3}>[ \t]?/m'                    => '',
            '/^[ \t]{0,3}(?:---|\*\*\*|___)[ \t]*$/m'  => '',
            '/\[([^\]]+)\]\((?:https?:\/\/[^\s)]+)\)/' => '$1',
            '/`([^`]+)`/'                              => '$1',
            '/\*\*([^*]+)\*\*/'                        => '$1',
            '/__([^_]+)__/'                            => '$1',
            '/(^|[^*])\*([^*\n]+)\*/'                  => '$1$2',
            '/~~([^~]+)~~/'                            => '$1',
            '/\n{3,}/'                                 => "\n\n",
        ];

        $out = str_replace("\r\n", "\n", $source);

        foreach ($patterns as $pattern => $replacement) {
            $out = preg_replace($pattern, $replacement, $out);
        }

        return trim($out);
    }
}
