<?php

declare(strict_types=1);

use App\Models\Caption;
use App\Utils\Const\ISO6391;
use App\Utils\ChineseVariant;
use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 把 captions.locale 統一成 ISO6391 的寫法（`zh_tw` → `zh-TW`）。
     *
     * 摘要那一欄在 `normalize_summaries_locale` 已經搬過一次，字幕留在舊寫法
     * 讓同一個語言在資料庫裡有兩種樣子，每個要比對語系的地方都得記得先正規化。
     * 寫入端（`Caption::LOCAL_*` 與三支字幕 job）已一併改掉，這支負責既有資料。
     *
     * 順帶改掉欄位預設值：建表時寫的是 `Caption::LOCAL_ZH_TW`，常數改了之後
     * 新環境建出來就是 `zh-TW`，但既有資料庫裡仍是 `zh_tw`，不改的話任何沒有
     * 明寫 locale 的插入都會再生出一筆舊寫法。
     */
    public function up(): void
    {
        foreach ($this->distinctLocales() as $locale) {
            $normalized = ISO6391::normalize($locale);

            if ($normalized === $locale) {
                continue;
            }

            DB::table('captions')->where('locale', $locale)->update(['locale' => $normalized]);
        }

        $this->resolveBareChinese();

        $this->setDefault(Caption::LOCAL_ZH_TW);
    }

    /**
     * 把只寫 `zh` 的字幕補上地區。
     *
     * 這些是 Groq 那條路徑留下的（`ISO6391::getCodeByName('Chinese')` 只到
     * `zh`）。`available_locales` 沒有純 `zh`，所以這種值永遠比不上任何使用者
     * 設定，摘要就選不到它。
     *
     * 為什麼這裡敢補、`normalize_summaries_locale` 當初不補：那支手上只有一個
     * 語言代碼，加地區純粹是猜；這裡有字幕原文，繁簡是看出來的，不是猜出來的。
     * 看不出來的（兩套用字一樣多）維持原狀，不硬塞。
     */
    private function resolveBareChinese(): void
    {
        DB::table('captions')
            ->where('locale', 'zh')
            ->select(['id', 'text'])
            ->orderBy('id')
            ->chunk(100, function ($captions) {
                foreach ($captions as $caption) {
                    $locale = ChineseVariant::detect((string) ($caption->text ?? ''));

                    if ($locale === null) {
                        continue;
                    }

                    DB::table('captions')->where('id', $caption->id)->update(['locale' => $locale]);
                }
            });
    }

    /**
     * 盡力還原成舊寫法。無法完全精確——`zh` 這種原本就沒有地區碼的值在 up()
     * 沒被改動，這裡也不該替它加上地區。
     */
    public function down(): void
    {
        foreach ($this->distinctLocales() as $locale) {
            if (!str_contains($locale, '-')) {
                continue;
            }

            DB::table('captions')
                ->where('locale', $locale)
                ->update(['locale' => strtolower(str_replace('-', '_', $locale))]);
        }

        $this->setDefault('zh_tw');
    }

    /**
     * 改欄位預設值，各 driver 分開處理。
     *
     * 不走 Blueprint 的 `->change()`：那條路徑要 doctrine/dbal，本專案沒有裝
     * （同 `change_oauths_tokens_to_text` 的判斷）。sqlite 沒有改預設值的語法，
     * 而測試庫是每次從 migration 重建的，建表當下讀的就是新的常數值，所以跳過
     * 它不會有落差。
     */
    private function setDefault(string $locale): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql', 'mysql' => DB::statement(
                sprintf("ALTER TABLE captions ALTER COLUMN locale SET DEFAULT '%s'", $locale)
            ),
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function distinctLocales(): array
    {
        return DB::table('captions')
            ->distinct()
            ->whereNotNull('locale')
            ->pluck('locale')
            ->map(fn ($locale) => (string) $locale)
            ->all();
    }
};
