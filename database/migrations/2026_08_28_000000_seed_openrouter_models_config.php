<?php

declare(strict_types=1);

use App\Models\Config;
use App\Utils\BaseMigration;
use App\Utils\AI\OpenRouterModels;

/*
 * 把「哪個 class 用哪個 OpenRouter 模型」的對照表寫進 configs。
 *
 * sync() 只補缺的 key，所以重跑不會蓋掉線上調過的值。
 */
return new class extends BaseMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        OpenRouterModels::sync();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Config::query()->where('key', Config::KEY_OPENROUTER_MODELS)->delete();
    }
};
