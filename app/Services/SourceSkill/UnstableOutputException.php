<?php

declare(strict_types=1);

namespace App\Services\SourceSkill;

use RuntimeException;

/**
 * 清洗跑到上限仍不穩定：每一輪都還會刪出新東西，代表輸出是刻意構造來對抗清洗規則的。
 * 不嘗試救回，整份當作產生失敗。
 */
class UnstableOutputException extends RuntimeException
{
}
