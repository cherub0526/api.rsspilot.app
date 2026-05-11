<?php
declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('download_enabled')->default(false)->after('chat_limit')->comment('可下載摘要/字幕');
            $table->boolean('agent_enabled')->default(false)->after('download_enabled')->comment('可使用 Agent 功能');
            $table->boolean('advanced_model_enabled')->default(false)->after('agent_enabled')->comment('可選擇進階 AI 模型');
            $table->boolean('custom_summary_enabled')->default(false)->after('advanced_model_enabled')->comment('可自訂影片總結');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('download_enabled');
            $table->dropColumn('agent_enabled');
            $table->dropColumn('advanced_model_enabled');
            $table->dropColumn('custom_summary_enabled');
        });
    }
};
