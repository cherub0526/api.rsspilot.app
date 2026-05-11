<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->foreignUlid('user_id')->nullable()->index()->after('id')->comment('使用者 ID');
        });
    }

    public function down(): void
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};
