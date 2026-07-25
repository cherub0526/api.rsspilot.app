<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('video_transcriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('media_id')->unique()->comment('媒體 ID');
            $table->mediumText('url_info')->nullable()->comment('videotranscriber.ai getUrlInfo 回應');
            $table->mediumText('start_transcription')->nullable()->comment('videotranscriber.ai startTranscription 回應');
            $table->mediumText('transcription')->nullable()->comment('videotranscriber.ai getTranscription 回應');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('videotranscriber.ai 轉錄紀錄');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_transcriptions');
    }
};
