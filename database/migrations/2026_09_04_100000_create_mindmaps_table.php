<?php

declare(strict_types=1);

use App\Models\Mindmap;
use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('mindmaps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('media_id')->comment('媒體ID');
            $table->foreignUlid('summary_id')->comment('來源摘要ID');
            $table->string('language')->comment('AI 語言代碼（setting.ai.language），如 ja / zh-TW');
            $table->foreignUlid('user_id')->nullable()->index()->comment('專屬使用者，null 為全站共用');
            $table->longText('markdown')->nullable()->comment('模型輸出的原始 markdown');
            $table->string('status')->default(Mindmap::STATUS_CREATED)->index()->comment('狀態');
            $table->string('ai_model')->nullable()->comment('產生所用的模型');
            $this->timestampsWithIndex($table, false);

            // 快取鍵。摘要因人而異（Media::summaryFor() 優先取使用者自己的那份），
            // 所以光靠 (media_id, language) 會讓自訂摘要使用者的心智圖被別人讀到；
            // 帶上 summary_id 之後，吃同一份共用摘要的多數使用者照樣共用同一列。
            $table->unique(['media_id', 'summary_id', 'language']);

            $table->comment('心智圖');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mindmaps');
    }
};
