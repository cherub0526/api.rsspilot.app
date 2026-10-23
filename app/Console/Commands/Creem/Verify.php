<?php

declare(strict_types=1);

namespace App\Console\Commands\Creem;

use Throwable;
use App\Models\Creem;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\CreemClient;
use Hypervel\Console\Command;

/**
 * 上線前的自我檢查：設定齊不齊、Creem 上的 product 是不是真的在。
 *
 * 存在的理由是 `creem:sync` 只看**我們自己的** `creems` 表決定「已存在」——
 * 表裡有列不代表 Creem 那邊真的有那個 product（換了帳號、换了環境、或別人把它
 * 刪了，都會讓兩邊對不上）。那種不一致會一路撐到使用者按下結帳才爆，
 * 所以要有一個可以隨時重跑、會真的去問 Creem 的指令。
 */
class Verify extends Command
{
    protected ?string $signature = 'creem:verify';

    protected string $description = '檢查 Creem 的設定與 product 對應是否正確';

    public function handle(): void
    {
        $client = new CreemClient();

        $this->info(sprintf('環境：%s（%s）', $client->isTestMode() ? 'test' : 'live', $client->baseUrl()));
        $this->newLine();

        $ok = $this->checkConfig($client);
        $ok = $this->checkProducts($client) && $ok;

        $this->newLine();

        if ($ok) {
            $this->info('✓ 全部通過。');

            return;
        }

        $this->error('✗ 有項目未通過，見上方。');
    }

    private function checkConfig(CreemClient $client): bool
    {
        $this->line('設定：');
        $ok = true;

        foreach (['CREEM_API_KEY', 'CREEM_WEBHOOK_SECRET'] as $key) {
            if ((string) env($key, '') === '') {
                $this->error("  ✗ {$key} 沒有設定");
                $ok = false;

                continue;
            }

            $this->line("  ✓ {$key} 已設定");
        }

        // test key 配 live base URL（或反過來）是最容易出、也最難查的錯：
        // 請求會回 401 而不是「你連錯環境了」。
        $apiKey = (string) env('CREEM_API_KEY', '');
        $looksTest = str_starts_with($apiKey, 'creem_test_');

        if ($apiKey !== '' && $looksTest !== $client->isTestMode()) {
            $this->error(sprintf(
                '  ✗ CREEM_API_KEY 看起來是 %s key，但 CREEM_TEST_MODE 指向 %s',
                $looksTest ? 'test' : 'live',
                $client->isTestMode() ? 'test' : 'live'
            ));
            $ok = false;
        }

        // Creem 是轉址型結帳，success_url 會直接丟給瀏覽器，**必須是絕對網址**。
        // 相對路徑不會在設定階段報錯，而是等到使用者結完帳要被導回來時才壞掉。
        $successUrl = (string) env('CREEM_SUCCESS_URL', '');

        if ($successUrl === '') {
            $this->error('  ✗ CREEM_SUCCESS_URL 沒有設定');
            $ok = false;
        } elseif (!preg_match('#^https?://#i', $successUrl)) {
            $this->error(sprintf(
                '  ✗ CREEM_SUCCESS_URL 必須是絕對網址（http:// 或 https:// 開頭），目前是「%s」',
                $successUrl
            ));
            $ok = false;
        } else {
            $this->line("  ✓ CREEM_SUCCESS_URL = {$successUrl}");
        }

        $default = (string) env('PAYMENT_DEFAULT_PROVIDER', '');

        if ($default === Subscription::PAYMENT_METHOD_CREEM) {
            $this->line('  ✓ PAYMENT_DEFAULT_PROVIDER = creem（新結帳會走 Creem）');
        } else {
            $this->warn(sprintf(
                '  ! PAYMENT_DEFAULT_PROVIDER = %s，新的結帳不會走 Creem',
                $default !== '' ? $default : '(未設定，預設 paddle)'
            ));
        }

        return $ok;
    }

    private function checkProducts(CreemClient $client): bool
    {
        $this->newLine();
        $this->line('Product 對應：');
        $ok = true;

        foreach (Price::with('plan')->get() as $price) {
            if ($price->stripeUnitAmount() <= 0) {
                continue;
            }

            // plan 可能是 null：price 的 plan 被軟刪除，或資料本身就是孤兒。
            // 這種 price 不會出現在 /v1/plans，也就不可能被結帳選到，跳過即可——
            // 但要出個聲，因為它同時代表 creem:sync 會替一筆沒人用的價格建 product。
            if (!$price->plan) {
                $this->warn(sprintf(
                    '  ! price %s（%s $%s）沒有對應的 plan，已跳過',
                    (string) $price->getKey(),
                    (string) $price->unit,
                    (string) $price->price
                ));

                continue;
            }

            $rows = $price->creem()->get();

            if ($rows->isEmpty()) {
                $this->error(sprintf(
                    '  ✗ %s %s 沒有任何 Creem product，請跑 creem:sync',
                    $price->plan->title,
                    $price->unit
                ));
                $ok = false;

                continue;
            }

            // 每個付費 price 都要有兩個變體，月繳與年繳一樣。缺了哪一個都是一條
            // 死路而不是退化：`productIdFor()` 找不到對應就拋例外，使用者看到的
            // 是結帳失敗。
            //
            // 年繳的 trial variant 特別容易缺：2026-10 中間那一版年繳改走首年
            // 折價、刻意不建它，所以在那段期間同步過的環境需要再跑一次
            // `creem:sync` 才會有。
            foreach (Sync::VARIANTS as $variant) {
                if ($rows->contains('variant', $variant)) {
                    continue;
                }

                $this->error(sprintf(
                    '  ✗ %s %s 缺少 %s variant，請跑 creem:sync（缺了這個，走到它的結帳會直接失敗）',
                    $price->plan->title,
                    $price->unit,
                    $variant
                ));
                $ok = false;
            }

            $ok = $this->checkIntroPrice($price) && $ok;

            foreach ($rows as $row) {
                $ok = $this->checkOneProduct($client, $price, (string) $row->variant, (string) $row->creem_id) && $ok;
            }
        }

        return $ok;
    }

    /**
     * 首年折價的資料本身合不合理。
     *
     * `first_period_price` 是手填的欄位，填錯不會讓任何東西報錯：
     * `Price::firstPeriodPrice()` 遇到 >= 原價或 <= 0 就當作沒有優惠，於是定價頁
     * 寫著首年折價、結帳卻照原價收——這是最難從現象反推回來的那種錯。
     */
    private function checkIntroPrice(Price $price): bool
    {
        $raw = $price->first_period_price;

        if ($raw === null) {
            return true;
        }

        $label = sprintf('%s %s', $price->plan->title, $price->unit);

        if (!$price->isAnnual()) {
            $this->error("  ✗ {$label} 設了 first_period_price，但只有年繳認這個欄位（月繳的優惠只有免費試用）");

            return false;
        }

        if ($price->firstPeriodPrice() === null) {
            $this->error(sprintf(
                '  ✗ %s 的 first_period_price（%s）不是有效的優惠價，必須 > 0 且 < %s',
                $label,
                (string) $raw,
                (string) $price->price
            ));

            return false;
        }

        // 提醒「不送試用」是刻意的：設了這個欄位就會把那個 price 從試用那條路
        // 切到折價那條路（見 CreemSubscriptionService::grantsTrial()），而這是
        // 從欄位本身看不出來的副作用。
        $this->line(sprintf(
            '  ✓ %s 首年 $%s → 續約 $%s（折 %d 美分，結帳時發單次折扣碼、不送免費試用）',
            $label,
            (string) $price->firstPeriodPrice(),
            (string) $price->price,
            (int) $price->introDiscountCents()
        ));

        return true;
    }

    private function checkOneProduct(CreemClient $client, Price $price, string $variant, string $productId): bool
    {
        $label = sprintf('%s %s/%s', $price->plan->title, $price->unit, $variant);

        try {
            $product = $client->getProduct($productId);
        } catch (Throwable $e) {
            $this->error(sprintf('  ✗ %s → %s 在 Creem 上查不到：%s', $label, $productId, mb_substr($e->getMessage(), 0, 100)));

            return false;
        }

        $expected = $price->stripeUnitAmount();
        $actual = (int) ($product['price'] ?? -1);
        $trialDays = $product['trial_period_days'] ?? null;
        $wantsTrial = $variant === Creem::VARIANT_TRIAL;

        $problems = [];

        if ($actual !== $expected) {
            $problems[] = sprintf('金額不符（我們 %d / Creem %d）', $expected, $actual);
        }

        if ($wantsTrial && !$trialDays) {
            $problems[] = 'trial 變體但 Creem 上沒有 trial_period_days';
        }

        // **天數也要比對，不能只看有沒有。** Creem 的 product 建了不能改價也不能改
        // 試用期，而 `creem:sync` 是只新增不修改的——所以 2026-10 之前同步過的
        // 環境，它的 trial product 仍然是 30 天，而程式與對外文案都已經是 14 天。
        // 那個落差不會讓任何東西報錯，只會讓第一筆扣款比我們承諾的晚 16 天。
        if ($wantsTrial && $trialDays && (int) $trialDays !== Subscription::TRIAL_DAYS) {
            $problems[] = sprintf(
                'Creem 上的試用是 %d 天，我們現在是 %d 天（product 不能改，要在 Creem 建新的再把 creems 對應列指過去）',
                (int) $trialDays,
                Subscription::TRIAL_DAYS
            );
        }

        if (!$wantsTrial && $trialDays) {
            // 這個方向更危險：沒資格的人會拿到試用期。
            $problems[] = sprintf('standard 變體但 Creem 上有 %s 天試用', (string) $trialDays);
        }

        // tax_mode 錯掉不會讓任何東西報錯，只會讓歐盟客戶的實收少一截（Creem 的
        // 預設 inclusive 會把 VAT 從我們的收入裡扣），所以要主動比對。
        $taxMode = (string) ($product['tax_mode'] ?? '');

        if ($taxMode !== Sync::TAX_MODE) {
            $problems[] = sprintf(
                'tax_mode 是「%s」，應為「%s」（Creem 的 product 不能改，要重建並改指過去）',
                $taxMode !== '' ? $taxMode : '未設',
                Sync::TAX_MODE
            );
        }

        $taxCategory = (string) ($product['tax_category'] ?? '');

        if ($taxCategory !== Sync::TAX_CATEGORY) {
            $problems[] = sprintf(
                'tax_category 是「%s」，應為「%s」',
                $taxCategory !== '' ? $taxCategory : '未設',
                Sync::TAX_CATEGORY
            );
        }

        if ($problems !== []) {
            $this->error(sprintf('  ✗ %s → %s：%s', $label, $productId, implode('、', $problems)));

            return false;
        }

        $this->line(sprintf(
            '  ✓ %-26s %d %s  tax_mode=%-9s tax_category=%-22s trial=%s',
            $label,
            $actual,
            (string) ($product['currency'] ?? '?'),
            (string) ($product['tax_mode'] ?? '(未設)'),
            (string) ($product['tax_category'] ?? '(未設)'),
            $trialDays ? "{$trialDays}d @" . (string) ($product['trial_price'] ?? '?') : '無'
        ));

        return true;
    }
}
