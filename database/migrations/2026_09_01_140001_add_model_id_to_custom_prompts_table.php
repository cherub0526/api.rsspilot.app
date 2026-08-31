<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

/*
 * custom_prompts 記錄這份設定要用哪個 AI 模型。
 *
 * 可為 null：模型是選填的，沒指定就退回系統預設（見 OpenRouterModels::for()）。
 * 既有資料也因此不需要回填。
 */
return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('custom_prompts', function (Blueprint $table) {
            $table->foreignUlid('model_id')->nullable()->index()->after('content')->comment('AI 模型 ID');
        });
    }

    public function down(): void
    {
        Schema::table('custom_prompts', function (Blueprint $table) {
            $table->dropColumn('model_id');
        });
    }
};
