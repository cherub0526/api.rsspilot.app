<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('user_sources', function (Blueprint $table) {
            $table->boolean('notify')->default(true)->after('source_id')->comment('是否開啟信件通知');
        });
    }

    public function down(): void
    {
        Schema::table('user_sources', function (Blueprint $table) {
            $table->dropColumn('notify');
        });
    }
};
