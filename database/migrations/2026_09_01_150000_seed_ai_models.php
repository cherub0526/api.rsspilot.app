<?php

declare(strict_types=1);

use App\Models\AiModel;
use App\Utils\BaseMigration;
use Database\Seeders\AiModelSeeder;

/*
 * 把可選模型型錄寫進 ai_models。
 *
 * 走 migration 而不是只留 seeder：seeder 不會在部署時自動執行，型錄是空的話
 * 使用者的模型下拉選單就沒有東西可選。做法與 seed_openrouter_models_config 一致。
 *
 * seeder 以 provider_model 為鍵 updateOrCreate，重跑安全。
 */
return new class extends BaseMigration {
    public function up(): void
    {
        (new AiModelSeeder())->run();
    }

    public function down(): void
    {
        AiModel::query()->forceDelete();
    }
};
