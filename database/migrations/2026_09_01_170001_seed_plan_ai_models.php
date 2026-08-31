<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Utils\BaseMigration;
use Database\Seeders\PlanAiModelSeeder;

/*
 * 建立方案與模型的初始對應。邏輯在 PlanAiModelSeeder，這裡只負責在部署時觸發它
 * ——seeder 不會自動執行，而沒有對應的話所有方案都沒有模型可用。
 */
return new class extends BaseMigration {
    public function up(): void
    {
        (new PlanAiModelSeeder())->run();
    }

    public function down(): void
    {
        foreach (Plan::query()->get() as $plan) {
            $plan->aiModels()->detach();
        }
    }
};
