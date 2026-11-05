<?php

declare(strict_types=1);

namespace App\Console\Commands\Plans;

use App\Models\Plan;

/**
 * `{plan}` 參數可以是 id 或 title。
 *
 * title 必須剛好命中一筆（security SR-6）：同名方案時不猜，直接失敗，否則可能把
 * 錯的方案藏起來或關掉。找不到、或命中多筆時回 null，並已經印出原因。
 */
trait ResolvesPlan
{
    private function resolvePlan(string $reference): ?Plan
    {
        $byId = Plan::query()->find($reference);

        if ($byId instanceof Plan) {
            return $byId;
        }

        $matches = Plan::query()->where('title', $reference)->limit(2)->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $this->error($matches->isEmpty()
            ? "找不到方案：{$reference}"
            : "有不只一個方案叫做「{$reference}」，請改用方案 id 指定。");

        return null;
    }
}
