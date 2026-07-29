<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('configs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 100)->unique()->comment('設定鍵');
            $table->mediumText('value')->nullable()->comment('設定值 (JSON)');
            $this->timestampsWithIndex($table, false);

            $table->comment('系統設定');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configs');
    }
};
