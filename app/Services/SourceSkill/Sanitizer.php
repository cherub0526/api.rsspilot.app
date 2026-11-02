<?php

declare(strict_types=1);

namespace App\Services\SourceSkill;

/**
 * 模型產生的 SKILL.md body 的確定性清洗（spec SR-1、SR-8）。
 *
 * 影片內容不可信，可能經摘要流進模型輸出，再被安裝這個 skill 的每一位使用者的 agent
 * 讀取或執行。skill 全站共用，所以不能只靠 prompt 叫模型別照抄——這裡在輸出端用
 * 固定規則把會被執行、會提權、會外連或像憑證的東西拿掉。
 *
 * 每個方法回傳 [清洗後的文字, 觸發的規則名稱]；規則名稱給 job 記 log 追蹤惡意頻道，
 * 刻意不帶任何原文。
 */
class Sanitizer
{
    public const RULE_CONTROL_CHARS = 'control_chars';

    public const RULE_FRONTMATTER = 'frontmatter';

    public const RULE_SHELL_CODE_BLOCK = 'shell_code_block';

    public const RULE_HTML = 'html';

    public const RULE_BANG_COMMAND = 'bang_command';

    public const RULE_TEMPLATE_VARIABLE = 'template_variable';

    public const RULE_FOREIGN_URL = 'foreign_url';

    public const RULE_CREDENTIAL = 'credential';

    public const REDACTED = '[REDACTED]';

    /** fence 可以放在清單項目或引用區塊裡（CommonMark container），開頭會有這些標記。 */
    private const CONTAINER_PREFIX = '^[ \t]*(?:(?:>|[-*+]|\d{1,9}[.)])[ \t]*)*';

    /** 正常輸出兩輪內就穩定；跑滿還在變就是刻意對抗。 */
    private const MAX_PASSES = 5;

    /** fenced code block 帶這些語言標記就整段移除，不只是去掉標記。 */
    private const SHELL_LANGUAGES = [
        'bash', 'sh', 'zsh', 'shell', 'console', 'shellsession', 'fish', 'ksh', 'csh', 'tcsh',
        'powershell', 'ps', 'ps1', 'pwsh', 'cmd', 'bat', 'batch', 'nu',
    ];

    /**
     * body 裡只保留這些網域（含子網域）的連結，值是允許的路徑。youtube.com 只留影片、頻道、
     * 播放清單類的頁面——`/redirect` 是外連轉址頁。
     */
    private const ALLOWED_URLS = [
        'youtube.com'  => '#^/(?:watch$|@[^/]+|channel/|playlist$|shorts/)#',
        'youtu.be'     => '#^/[A-Za-z0-9_-]{6,20}$#',
        'rsspilot.app' => '#^#',
    ];

    /**
     * 常見憑證格式。高熵字串另外判斷（見 redactHighEntropy），因為要看字元組成。
     */
    private const CREDENTIAL_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i',
        '/\bsk-[A-Za-z0-9_-]{16,}/',
        '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{8,}/',
        '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        '/\bxox[abposr]-[A-Za-z0-9-]{10,}/',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/',
        // Sanctum token（RSSPilot 自己的 API key）：`<id>|<secret>`，前面可能帶 token_prefix
        '/\b(?:rsp_)?\d+\|[A-Za-z0-9_]{16,}/',
        '/\brsp_[A-Za-z0-9_|]{8,}/',
    ];

    /**
     * @return array{0: string, 1: list<string>}
     * @throws UnstableOutputException
     */
    public function body(string $text): array
    {
        $rules = [];

        $text = $this->untilStable(
            str_replace(["\r\n", "\r"], "\n", $text),
            function (string $t) use (&$rules): string { return $this->bodyPass($t, $rules); }
        );

        return [$text, $rules];
    }

    /**
     * 單行的不可信文字（影片標題、頻道名稱）：去掉控制字元與換行、HTML、外連網址與憑證，
     * 讓它只能是一段純文字。markdown 的跳脫由呼叫端依插入位置決定。
     *
     * @return array{0: string, 1: list<string>}
     * @throws UnstableOutputException
     */
    public function inline(string $text): array
    {
        $rules = [];

        $text = $this->untilStable($text, function (string $t) use (&$rules): string { return $this->inlinePass($t, $rules); });

        return [$text, $rules];
    }

    public function isAllowedUrl(string $url): bool
    {
        // 瀏覽器與 WHATWG 解析器把 `\` 當成 `/`、parse_url 不會；兩邊對 host 的認定會不一樣
        if (preg_match('/[\\\\\s\x00-\x1F\x7F]|%2f|%5c/i', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if (!preg_match('/^[a-z0-9.-]+$/', $host) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // 瀏覽器會先解掉 `.`、`..`：`/@x/../../redirect` 看起來在白名單裡，實際連到外連轉址頁
        $path = $parts['path'] ?? '/';
        if (preg_match('#(?:^|/)\.{1,2}(?:/|$)|%2e#i', $path)) {
            return false;
        }

        foreach (self::ALLOWED_URLS as $allowed => $paths) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return (bool) preg_match($paths, $path);
            }
        }

        return false;
    }

    /**
     * 整串規則重複跑到輸出不再改變。只跑一次的話，後面的規則刪掉中間的東西會讓兩側黏回
     * 前面的規則要擋的形狀（`!${x}` 加反引號，刪掉 `${x}` 就成了會被執行的指令）。
     *
     * @param callable(string): string $pass
     */
    private function untilStable(string $text, callable $pass): string
    {
        for ($i = 0; $i < self::MAX_PASSES; ++$i) {
            $next = $pass($text);
            if ($next === $text) {
                return $text;
            }
            $text = $next;
        }

        throw new UnstableOutputException('Skill output did not stabilise under sanitising.');
    }

    /**
     * 刪除型規則一律以空白取代被刪的內容，不讓兩側字元直接黏起來；`!` 加反引號放最後。
     *
     * @param list<string> $rules
     */
    private function bodyPass(string $text, array &$rules): string
    {
        $text = $this->apply($text, self::RULE_CONTROL_CHARS, $rules, fn (string $t) => $this->stripControlChars($t));
        $text = $this->apply($text, self::RULE_FRONTMATTER, $rules, fn (string $t) => $this->stripFrontmatter($t));
        $text = $this->apply($text, self::RULE_SHELL_CODE_BLOCK, $rules, fn (string $t) => $this->stripUnsafeFences($t));
        $text = $this->apply($text, self::RULE_HTML, $rules, fn (string $t) => $this->stripHtml($t));
        $text = $this->apply($text, self::RULE_TEMPLATE_VARIABLE, $rules, fn (string $t) => $this->stripTemplateVariables($t));
        $text = $this->apply($text, self::RULE_FOREIGN_URL, $rules, fn (string $t) => $this->stripForeignUrls($t));
        $text = $this->apply($text, self::RULE_CREDENTIAL, $rules, fn (string $t) => $this->redactCredentials($t));
        $text = $this->apply($text, self::RULE_BANG_COMMAND, $rules, fn (string $t) => $this->stripBangCommands($t));

        // `---` 單獨一行只剩分隔線的作用，留著會讓下游 parser 有機會把兩條之間當成 frontmatter
        $text = (string) preg_replace('/^[ \t]*-{3,}[ \t]*$/m', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * @param list<string> $rules
     */
    private function inlinePass(string $text, array &$rules): string
    {
        $text = (string) preg_replace('/[\r\n\t]+/', ' ', $text);
        $text = $this->apply($text, self::RULE_CONTROL_CHARS, $rules, fn (string $t) => $this->stripControlChars($t));
        $text = $this->apply($text, self::RULE_HTML, $rules, fn (string $t) => $this->stripHtml($t));
        $text = $this->apply($text, self::RULE_TEMPLATE_VARIABLE, $rules, fn (string $t) => $this->stripTemplateVariables($t));
        $text = $this->apply($text, self::RULE_FOREIGN_URL, $rules, fn (string $t) => $this->stripForeignUrls($t));
        $text = $this->apply($text, self::RULE_CREDENTIAL, $rules, fn (string $t) => $this->redactCredentials($t));
        $text = $this->apply($text, self::RULE_BANG_COMMAND, $rules, fn (string $t) => $this->stripBangCommands($t));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param list<string> $rules
     * @param callable(string): string $rule
     */
    private function apply(string $text, string $name, array &$rules, callable $rule): string
    {
        $result = $rule($text);
        if ($result !== $text && !in_array($name, $rules, true)) {
            $rules[] = $name;
        }

        return $result;
    }

    /** 保留換行與 tab；Unicode 的雙向覆寫字元會讓畫面上看到的與 agent 讀到的不一樣，一併移除。 */
    private function stripControlChars(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);
    }

    /**
     * 開頭的 frontmatter 一律丟掉（系統會自己組），body 中間像 frontmatter 的區塊也一樣——
     * 那正是用來夾帶 `allowed-tools`、`hooks` 的寫法。
     */
    private function stripFrontmatter(string $text): string
    {
        $text = (string) preg_replace('/\A\s*---[ \t]*\n.*?\n---[ \t]*(?:\n|\z)/s', '', $text);

        return (string) preg_replace(
            '/^[ \t]*---[ \t]*\n(?:[ \t]*(?:[A-Za-z0-9_-]+[ \t]*:.*)?\n|[ \t]*-[ \t].*\n|[ \t]+.*\n)*?[ \t]*---[ \t]*$/m',
            '',
            $text
        );
    }

    /**
     * fenced code block 只保留沒有 info string、或 info string 是一般語言標記的。
     * shell 類語言整段移除；`!` 開頭（Claude Code 的多行動態指令，載入時直接執行）或任何
     * 其他形狀的 info string 也整段移除，沒關上的一路刪到結尾。
     */
    private function stripUnsafeFences(string $text): string
    {
        $out = [];
        $fence = null;
        $dropping = false;

        foreach (explode("\n", $text) as $line) {
            if ($fence === null) {
                if (preg_match('/' . self::CONTAINER_PREFIX . '(`{3,}|~{3,})(.*)$/', $line, $m)) {
                    $fence = $m[1];
                    $dropping = !$this->isSafeInfoString(trim($m[2]));
                }
                if (!$dropping) {
                    $out[] = $line;
                }

                continue;
            }

            $closes = (bool) preg_match('/' . self::CONTAINER_PREFIX . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/', $line);
            if (!$dropping) {
                $out[] = $line;
            }
            if ($closes) {
                $fence = null;
                $dropping = false;
            }
        }

        return implode("\n", $out);
    }

    private function isSafeInfoString(string $info): bool
    {
        if ($info === '') {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z0-9_+.#-]+$/', $info)
            && !in_array(strtolower($info), self::SHELL_LANGUAGES, true);
    }

    private function stripHtml(string $text): string
    {
        $text = (string) preg_replace('/<(script|style|iframe)\b.*?(?:<\/\1\s*>|\z)/is', ' ', $text);
        $text = (string) preg_replace('/<!--.*?(?:-->|\z)/s', ' ', $text);

        return (string) preg_replace('/<\/?[A-Za-z][A-Za-z0-9:-]*(?:\s[^<>]*)?\/?>/', ' ', $text);
    }

    /**
     * Claude Code 載入 skill 時會直接執行 `` !`command` `` 與 ```` ```! ```` 區塊，不經模型判斷。
     * fence 後面的 `!` 不看所在位置一律拿掉：清單、引用、外層 fence 裡的寫法，
     * stripUnsafeFences 依結構判斷不一定抓得到，而 Claude Code 怎麼解析我們無法確認。
     */
    private function stripBangCommands(string $text): string
    {
        do {
            $before = $text;
            $text = (string) preg_replace('/!\s*`/', '`', $text);
            $text = (string) preg_replace('/(`{3,}|~{3,})[ \t]*!+/', '$1', $text);
        } while ($text !== $before);

        return $text;
    }

    /** `$ARGUMENTS`、`$1` 會被 Claude Code 代換成使用者輸入，`${…}` 是 skill 的環境變數。 */
    private function stripTemplateVariables(string $text): string
    {
        $text = (string) preg_replace('/\$ARGUMENTS(?:\[\d+\])?|\$\d+\b/', ' ', $text);

        return (string) preg_replace('/\$\{[^}\n]*\}?/', ' ', $text);
    }

    private function stripForeignUrls(string $text): string
    {
        // markdown 連結：網址不在白名單就只留文字
        $text = (string) preg_replace_callback(
            '/(!?)\[([^\]\n]*)\]\(\s*<?([^)\s>]*)>?(?:\s+"[^"\n]*")?\s*\)/',
            fn (array $m) => $this->isAllowedUrl($m[3]) ? $m[0] : $m[2],
            $text
        );

        $text = (string) preg_replace_callback(
            '/\b(?:https?:\/\/|www\.)[^\s<>()\[\]"\'`]+|(?<![:\w\/])\/\/[A-Za-z0-9.-]+\.[A-Za-z]{2,}[^\s<>()\[\]"\'`]*/i',
            function (array $m): string {
                $url = $m[0];
                if (str_starts_with($url, '//')) {
                    $url = 'https:' . $url;
                } elseif (str_starts_with(strtolower($url), 'www.')) {
                    $url = 'https://' . $url;
                }

                return $this->isAllowedUrl($url) ? $m[0] : ' ';
            },
            $text
        );

        return (string) preg_replace('/\b(?:javascript|data|vbscript|file|ftp):\S+/i', ' ', $text);
    }

    private function redactCredentials(string $text): string
    {
        $text = (string) preg_replace(self::CREDENTIAL_PATTERNS, self::REDACTED, $text);

        return $this->redactHighEntropy($text);
    }

    /**
     * 長度 ≥ 32 的十六進位字串（含數字與字母），或大小寫與數字混雜的 token（base64、隨機金鑰）。
     * 只有單一字元類別的長字串（`cccc…`、`wwww…`）不是金鑰，不能遮。
     */
    private function redactHighEntropy(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/\b[0-9a-fA-F]{32,}\b/',
            fn (array $m) => preg_match('/\d/', $m[0]) && preg_match('/[a-fA-F]/', $m[0]) ? self::REDACTED : $m[0],
            $text
        );

        return (string) preg_replace_callback(
            '/[A-Za-z0-9+\/_-]{32,}={0,2}/',
            fn (array $m) => preg_match('/[a-z]/', $m[0]) && preg_match('/[A-Z]/', $m[0]) && preg_match('/\d/', $m[0])
                ? self::REDACTED
                : $m[0],
            $text
        );
    }
}
