<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->foreignUlid('media_id')->index()->comment('媒體 ID');
            $table->string('title')->nullable()->comment('對話標題');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('AI 對話 Session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
