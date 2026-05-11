<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type')->index()->comment('類型: youtube_channel | youtube_playlist');
            $table->string('external_id')->index()->comment('外部平台 ID（YouTube channel/playlist ID）');
            $table->string('title')->nullable()->comment('標題');
            $table->string('url', 1024)->comment('網址');
            $table->string('thumbnail')->nullable()->comment('縮圖');
            $table->text('description')->nullable()->comment('描述');
            $table->text('metadata')->nullable()->comment('JSON: subscriber_count, video_count 等');
            $table->timestamp('last_synced_at')->nullable()->index()->comment('最後同步時間');
            $table->string('status')->default('active')->index()->comment('狀態');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('訂閱來源（頻道或播放清單）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
