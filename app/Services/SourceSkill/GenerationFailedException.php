<?php

declare(strict_types=1);

namespace App\Services\SourceSkill;

use RuntimeException;

/**
 * 交回佇列重試用的例外。訊息只有原本例外的類別名稱、不串接原例外：佇列 worker
 * 會把例外訊息寫進 log 與 failed_jobs，而模型連線的例外可能帶著 response body。
 */
class GenerationFailedException extends RuntimeException
{
    public static function from(string $reason): self
    {
        return new self("Source skill generation failed: {$reason}");
    }
}
