<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * email 從「註冊時填的欄位」升格為登入識別，因此需要唯一約束。
     *
     * 這個索引同時是併發下的最後防線：Validator 的 unique 規則是「先查再寫」，
     * 兩個同時進來的註冊請求會雙雙通過檢查再各自 insert。Swoole 協程的併發模型
     * 讓這件事比 PHP-FPM 更容易發生，DB 層的約束才是真正擋得住的那一層。
     *
     * 沿用專案慣例不建立 FK constraint，但唯一索引不同——它是資料正確性本身。
     *
     * 注意：email 仍維持 nullable。PostgreSQL 的唯一索引視每個 NULL 為相異值，
     * 所以既有的 NULL email 不會擋住這個 migration；擋得住的是**非空的重複值**。
     * 部署前請先確認 production 沒有重複，否則 migrate 會失敗：
     *
     *   SELECT email, COUNT(*) FROM users
     *   WHERE email IS NOT NULL AND email <> ''
     *   GROUP BY email HAVING COUNT(*) > 1;
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
