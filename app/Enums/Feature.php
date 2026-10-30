<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 全站功能開關（config/features.php）。值就是 config 的 key，也是路由
 * middleware `feature:<value>` 的參數。
 */
enum Feature: string
{
    case Transcript = 'transcript';
    case Screenshot = 'screenshot';
    case ChannelSkill = 'channel_skill';

    public function enabled(): bool
    {
        return (bool) config('features.' . $this->value, false);
    }
}
