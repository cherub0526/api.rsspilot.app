<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 使用者自己產生的 API key。
     *
     * 這張表是 sanctum 的，但**不能直接用它附的 migration**：那一支用
     * `$table->morphs('tokenable')`，`tokenable_id` 會是 unsigned big integer，
     * 而這個專案的 `users.id` 是 26 字元的 ULID。sqlite 的動態型別會讓它在本機
     * 看起來正常，到了 MySQL／MariaDB 則是靜默截斷成 0——**每一把 token 都會指向
     * 同一個不存在的使用者**，而且不會有任何錯誤訊息。
     *
     * 所以 tokenable_id 自己宣告成 ulid，索引照 sanctum 原本的形狀建。
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type')->comment('持有者的 model class');
            $table->ulid('tokenable_id')->comment('持有者 ID');
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->text('name')->comment('使用者自己取的名稱');
            $table->string('token', 64)->unique()->comment('token 的 sha256');
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable()->comment('最後一次被使用的時間');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
