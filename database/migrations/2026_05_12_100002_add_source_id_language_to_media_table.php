<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->foreignUlid('source_id')->nullable()->index()->after('resource_id')->comment('來源 ID');
            $table->string('language')->nullable()->after('duration')->comment('影片主語言');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('source_id');
            $table->dropColumn('language');
        });
    }
};
