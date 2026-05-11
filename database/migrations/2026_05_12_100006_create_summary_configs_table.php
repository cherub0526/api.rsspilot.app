<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('summary_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->string('title')->comment('設定名稱');
            $table->string('prompt_type')->default('custom')->index()->comment('提示類型: default|notes|business|tldr|custom');
            $table->text('content')->nullable()->comment('自訂提示內容');
            $table->string('ai_model')->nullable()->comment('AI 模型');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('摘要設定（原 custom_prompts，擴充版）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_configs');
    }
};
