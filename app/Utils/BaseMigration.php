<?php

declare(strict_types=1);

namespace App\Utils;

use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

abstract class BaseMigration extends Migration
{
    /**
     * Add indexed timestamps (created_at, updated_at) and optional soft deletes.
     *
     * @param bool $nullable make timestamps nullable
     * @param bool $withSoftDeletes add softDeletes column
     */
    protected function timestampsWithIndex(
        Blueprint $table,
        bool $nullable = false,
        bool $withSoftDeletes = false
    ): void {
        if ($nullable) {
            $table->timestamp('created_at')->nullable()->index()->comment('創建時間');
            $table->timestamp('updated_at')->nullable()->index()->comment('更新時間');
        } else {
            $table->timestamp('created_at')->index()->comment('創建時間');
            $table->timestamp('updated_at')->index()->comment('更新時間');
        }

        if ($withSoftDeletes) {
            $table->softDeletes()->comment('刪除時間');
        }
    }
}
