<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber;

final readonly class PollResult
{
    /**
     * @param list<array{start: float, end: float, text: string}> $segments 驗證過、非空白的段落
     * @param string $language 官方偵測的語言，normalize 後在 ISO6391 白名單內才有值，否則 ''
     * @param null|int $duration 0–24 小時內的秒數，否則 null
     * @param null|array<string, mixed> $archive 要寫進 S3 的 /result（白名單欄位，不含簽名網址）
     * @param null|array<string, mixed> $failure 失敗時寫進 `video_transcriptions.transcription` 的內容
     * @param bool $alert 需要記 error log
     */
    public function __construct(
        public Outcome $outcome,
        public array $segments = [],
        public string $language = '',
        public ?int $duration = null,
        public ?array $archive = null,
        public ?array $failure = null,
        public bool $alert = false,
    ) {
    }
}
