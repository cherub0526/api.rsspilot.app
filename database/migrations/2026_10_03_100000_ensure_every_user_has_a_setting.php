<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 每個使用者都恰好有一筆 settings。
     *
     * 建表時（2026_01_21）幫當時的使用者都補了一筆，但之後註冊的帳號要等第一次
     * 存設定才會有——在那之前語系、AI 語言全落回預設值。新帳號現在由
     * UserObserver::created 建立，這支負責補上中間這段時間註冊、還沒有設定的人，
     * 再用唯一索引擋住第二筆（SettingsController 的 firstOrCreate 在協程併發下
     * 是「先查再寫」，兩個請求可能各插一筆）。
     *
     * 補的是空設定而不是 locale = en：空的代表「沒有偏好」，SetLocale 才會繼續
     * 參考 Accept-Language；寫死 en 會蓋掉它。
     *
     * 沿用專案慣例不建立 FK constraint。部署前請先確認 production 沒有重複，
     * 否則唯一索引會建不起來：
     *
     *   SELECT user_id, COUNT(*) FROM settings GROUP BY user_id HAVING COUNT(*) > 1;
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('settings')
                    ->whereColumn('settings.user_id', 'users.id');
            })
            ->orderBy('id')
            ->chunk(500, function ($users) {
                $now = now();

                DB::table('settings')->insert($users->map(fn ($user) => [
                    'user_id'    => $user->id,
                    'data'       => json_encode([]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        Schema::table('settings', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};
