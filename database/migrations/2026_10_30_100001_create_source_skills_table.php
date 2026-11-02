<?php

declare(strict_types=1);

use App\Models\SourceSkill;
use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('source_skills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_id')->comment('來源 ID');
            $table->string('language')->comment('AI 語言代碼（setting.ai.language），如 ja / zh-TW');
            $table->string('name', SourceSkill::NAME_MAX_LENGTH)->nullable()->comment('skill 名稱（frontmatter name，系統決定）');
            $table->text('description')->nullable()->comment('skill 描述（frontmatter description）');
            $table->longText('content')->nullable()->comment('SKILL.md 全文');
            $table->longText('references')->nullable()->comment('references/videos.md 全文');
            $table->unsignedInteger('video_count')->default(0)->comment('取材的影片數');
            $table->json('media_ids')->nullable()->comment('本次取材的媒體 ID，用來計算之後新增幾支');
            $table->string('status')->default(SourceSkill::STATUS_GENERATING)->index()->comment('狀態：generating / completed / failed');
            $table->timestamp('started_at')->nullable()->comment('最近一次開始產生的時間，用來判斷產生中是否已失效');
            $table->string('ai_model')->nullable()->comment('產生所用的模型');
            $table->timestamp('generated_at')->nullable()->comment('最近一次成功產生的時間');
            $table->timestamp('failed_at')->nullable()->comment('最近一次產生失敗的時間');
            $this->timestampsWithIndex($table, false);

            // 同頻道同語言全站共用一份。GET 插隊與排程可能同時為同一組派工，
            // 這個唯一索引是併發下的最後防線。
            $table->unique(['source_id', 'language']);

            $table->comment('頻道 Skill：依 (來源, AI 語言) 全站共用一份');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_skills');
    }
};
