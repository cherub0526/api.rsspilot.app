<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

/*
 * 方案能使用哪些 AI 模型。
 *
 * 這張表是「誰能用哪些模型」的**唯一**授權來源。
 *
 * ai_models 刻意沒有「層級」欄位。授權與標籤分開存放的話兩者必然漂移——把旗艦
 * 模型開放給免費方案之後，標籤仍寫著旗艦，而前端要嘛信錯的那個、要嘛得自己判斷
 * 該信哪個。前端的分組改為從這張表推導（見 AiModelResource），來源就只有一個。
 */
return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('plan_ai_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->index()->comment('方案 ID');
            $table->foreignUlid('ai_model_id')->index()->comment('AI 模型 ID');
            $this->timestampsWithIndex($table, false, false);

            $table->unique(['plan_id', 'ai_model_id']);
            $table->comment('方案可使用的 AI 模型');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_ai_models');
    }
};
