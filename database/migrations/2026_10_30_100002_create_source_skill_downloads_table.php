<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('source_skill_downloads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->foreignUlid('source_skill_id')->index()->comment('頻道 Skill ID');
            $table->string('kind')->comment('取得方式：zip / install');
            $table->timestamp('created_at')->nullable()->index()->comment('下載時間');

            $table->comment('頻道 Skill 下載紀錄（成功指標用，桌面端無 GTM 只能靠這裡）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_skill_downloads');
    }
};
