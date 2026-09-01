<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

/*
 * 使用者可選的 AI 模型清單。
 *
 * 與 App\Utils\AI\OpenRouterModels 的分工：那一支管「系統某個用途預設用哪個模型」
 * （configs 表裡的 用途 → 模型 對照），這張表管「使用者可以從哪些模型裡挑」。
 * 兩者的鍵、生命週期與異動頻率都不同，共用一份會讓「換掉摘要的預設模型」與
 * 「下架某個可選模型」變成同一個動作。
 *
 * 沒有「層級」欄位是刻意的：「誰能用哪些模型」由 plan_ai_models 表達。標籤與授權
 * 分開存放的結果就是兩者會漂移——把旗艦模型開放給免費方案之後，標籤仍寫著旗艦。
 */
return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->comment('顯示名稱');
            $table->string('provider_model')->index()->comment('供應商的模型代號，如 openai/gpt-4.1-mini');
            $table->boolean('enabled')->default(true)->index()->comment('是否開放選用');
            $table->unsignedInteger('sort')->default(0)->index()->comment('排序');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('可供使用者選用的 AI 模型');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
