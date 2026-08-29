<?php

declare(strict_types=1);

use App\Utils\Const\ISO6391;
use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 把 summaries.locale 統一成 ISO6391 的寫法（`zh_tw` → `zh-TW`）。
     *
     * 這一欄原本沿用字幕的 `Caption::LOCAL_*` 寫法，跟 `settings.data.locale`
     * 存的 `config('app.available_locales')` 寫法不相等，於是「依使用者語系挑
     * 摘要」永遠只有 `en` 對得上。寫入端已一併改為正規化後再存，這支負責既有
     * 資料。
     */
    public function up(): void
    {
        foreach ($this->distinctLocales() as $locale) {
            $normalized = ISO6391::normalize($locale);

            if ($normalized === $locale) {
                continue;
            }

            DB::table('summaries')->where('locale', $locale)->update(['locale' => $normalized]);
        }
    }

    /**
     * 盡力還原成字幕那套寫法。無法完全精確——`zh` 這種原本就沒有地區碼的值
     * 在 up() 沒被改動，這裡也不該替它加上地區。
     */
    public function down(): void
    {
        foreach ($this->distinctLocales() as $locale) {
            if (!str_contains($locale, '-')) {
                continue;
            }

            DB::table('summaries')
                ->where('locale', $locale)
                ->update(['locale' => strtolower(str_replace('-', '_', $locale))]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function distinctLocales(): array
    {
        return DB::table('summaries')
            ->distinct()
            ->whereNotNull('locale')
            ->pluck('locale')
            ->map(fn ($locale) => (string) $locale)
            ->all();
    }
};
