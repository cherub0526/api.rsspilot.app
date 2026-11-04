<?php

declare(strict_types=1);

namespace App\Utils;

use Hyperf\Database\Model\Builder;

/**
 * 「欄位包含這段關鍵字」的 LIKE 條件，關鍵字裡的 %、_、\ 都只當一般字元。
 *
 * 一定要明確寫 ESCAPE：Postgres 預設用 \ 跳脫，但測試用的 SQLite 沒有預設跳脫字元，
 * 不寫的話兩邊行為不同，測試也守不住跳脫。
 */
final class Like
{
    /**
     * @param string $column 欄位名稱只能由程式碼指定，不可來自使用者輸入
     */
    public static function contains(Builder $query, string $column, string $keyword): Builder
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        $query->whereRaw("{$wrapped} like ? escape '\\'", ['%' . self::escape($keyword) . '%']);

        return $query;
    }

    private static function escape(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
    }
}
