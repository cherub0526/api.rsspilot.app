<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->index()->comment('Session ID');
            $table->string('role')->index()->comment('角色: user | ai');
            $table->text('content')->comment('訊息內容');
            $table->timestamp('created_at')->nullable()->index()->comment('創建時間');

            $table->comment('AI 對話訊息');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
