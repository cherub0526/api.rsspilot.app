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

            foreach ($rows as $row) {
                $ok = $this->checkOneProduct($client, $price, (string) $row->variant, (string) $row->creem_id) && $ok;
            }
        }

        return $ok;
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

        if (!$wantsTrial && $trialDays) {
            // 這個方向更危險：沒資格的人會拿到免費月。
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
