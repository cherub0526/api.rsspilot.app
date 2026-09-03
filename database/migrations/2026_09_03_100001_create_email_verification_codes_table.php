<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    /**
     * 註冊後的 email 驗證憑證。
     *
     * 一筆 row 同時支撐兩條驗證路徑：使用者手動輸入的 6 位數 code，以及信件連結
     * 帶的 token。兩者指向同一筆，用掉一個另一個即失效——所以是同一張表的同一列，
     * 不是兩套機制。
     */
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->string('code', 6)->comment('信中顯示的 6 位數驗證碼');
            $table->string('token', 64)->comment('信件連結帶的一次性 token，與 code 等價');
            $table->unsignedInteger('attempts')->default(0)->comment('已嘗試輸入錯誤的次數');
            $table->timestamp('expires_at')->comment('失效時間');
            $table->timestamp('consumed_at')->nullable()->comment('已被使用的時間，null 表示尚未使用');
            $this->timestampsWithIndex($table, false);

            // 信件連結會拿 token 直接查，必須唯一才能定位到單一筆
            $table->unique('token');
            // 驗證時的查詢路徑是 (user_id, code)；同時讓「找該使用者最新一筆」走索引
            $table->index(['user_id', 'expires_at']);

            $table->comment('註冊 email 驗證的一次性憑證（6 位數碼與信件連結共用同一筆）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
