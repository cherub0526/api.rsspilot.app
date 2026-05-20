<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('free')->default(false)->after('status')->comment('免費：免費來源任何使用者皆可觀看');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('free');
        });
    }
};
