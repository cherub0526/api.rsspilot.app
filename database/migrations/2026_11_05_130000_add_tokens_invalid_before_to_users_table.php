<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tokens_invalid_before')->nullable()
                ->comment('登入憑證失效時間點：JWT 的 iat 早於此時間一律拒絕；重設密碼、刪除帳號時寫入');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tokens_invalid_before');
        });
    }
};
