<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rss', function (Blueprint $table) {
            $table->string('list_id')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('rss', function (Blueprint $table) {
            $table->dropColumn('list_id');
        });
    }
};
