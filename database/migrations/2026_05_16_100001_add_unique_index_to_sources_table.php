<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->unique(['type', 'external_id'], 'sources_type_external_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropUnique('sources_type_external_id_unique');
        });
    }
};
