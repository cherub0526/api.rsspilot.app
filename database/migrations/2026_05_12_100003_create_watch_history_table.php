<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('watch_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->foreignUlid('media_id')->index()->comment('媒體 ID');
            $table->unsignedInteger('progress_seconds')->default(0)->comment('觀看進度（秒）');
            $table->boolean('completed')->default(false)->index()->comment('是否看完');
            $table->timestamp('watched_at')->index()->comment('觀看時間');
            $this->timestampsWithIndex($table, false, false);

            $table->comment('觀看紀錄');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_history');
    }
};
