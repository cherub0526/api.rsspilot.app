<?php

declare(strict_types=1);

namespace Database\Seeders;

use Hypervel\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PlanPriceSeeder::class,
            OpenRouterModelsSeeder::class,
        ]);
    }
}
