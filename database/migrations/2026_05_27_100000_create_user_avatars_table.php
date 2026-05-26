<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('user_avatars', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->string('filename')->comment('客戶端原始檔名，e.g. my-photo.jpg');
            $table->string('path')->comment('S3 相對路徑，e.g. avatars/{userId}/{uuid}.jpg');
            $this->timestampsWithIndex($table, false, false);

            $table->comment('使用者頭像上傳紀錄');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_avatars');
    }
};
