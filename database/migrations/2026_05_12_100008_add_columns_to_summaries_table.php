<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->foreignUlid('config_id')->nullable()->index()->after('media_id')->comment('Summary Config ID');
            $table->string('ai_model')->nullable()->after('status')->comment('使用的 AI 模型');
            $table->string('prompt_type')->nullable()->after('ai_model')->comment('提示類型');
        });
    }

    public function down(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->dropColumn('config_id');
            $table->dropColumn('ai_model');
            $table->dropColumn('prompt_type');
        });
    }
};
