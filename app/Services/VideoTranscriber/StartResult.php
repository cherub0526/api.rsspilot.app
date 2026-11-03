<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber;

final readonly class StartResult
{
    /**
     * @param array<string, mixed> $record 併入 `start_transcription` 的內容，只含系統值與白名單欄位
     * @param bool $alert 需要記 error log（額度不足、請求格式錯誤這類要有人處理的情況）
     */
    public function __construct(
        public Outcome $outcome,
        public array $record,
        public bool $alert = false,
    ) {
    }
}
