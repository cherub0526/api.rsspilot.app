<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

/*
 * ai_models 補上從 OpenRouter 同步回來的欄位。
 *
 * 價格存「每百萬 token 美元」而不是來源的「每 token」：來源值長這樣 0.0000004，
 * 存成那個精度要 decimal(16,12)，而且沒有人是用那個單位在談價格的。
 * decimal(12,6) 足以表示 0.02 到 25.000000 這個區間。
 *
 * 兩個價格都可為 null——同步之前建立的資料列沒有這個資訊，而 0 會被誤讀成免費。
 */
return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->boolean('supports_thinking')->default(false)->index()->after('enabled')->comment('是否支援思考（reasoning）');
            $table->decimal('input_price', 12, 6)->nullable()->after('supports_thinking')->comment('輸入價格：每百萬 token 美元');
            $table->decimal('output_price', 12, 6)->nullable()->after('input_price')->comment('輸出價格：每百萬 token 美元');
            $table->timestamp('synced_at')->nullable()->index()->after('sort')->comment('最後與供應商同步的時間');
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn(['supports_thinking', 'input_price', 'output_price', 'synced_at']);
        });
    }
};
