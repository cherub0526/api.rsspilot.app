<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('user_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->foreignUlid('source_id')->index()->comment('來源 ID');
            $this->timestampsWithIndex($table, false, false);

            $table->unique(['user_id', 'source_id']);
            $table->comment('使用者訂閱的來源');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sources');
    }
};
