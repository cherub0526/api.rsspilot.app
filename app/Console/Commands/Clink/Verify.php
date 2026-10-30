<?php

declare(strict_types=1);

namespace App\Console\Commands\Clink;

use Throwable;
use App\Models\Clink;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\ClinkClient;
use Hypervel\Console\Command;
use App\Http\Controllers\API\V1\Webhook\ClinkController;

/**
 * 上線前與部署後的自我檢查：設定齊不齊、Clink 上的 product / price 是不是真的對、
 * webhook 有沒有訂齊事件。只讀不寫，可以隨時重跑；有任何一項不通過 exit code 為 1。
 *
 * `clinks` 表裡有列不代表 Clink 那邊真的對得上（換了環境、有人在 dashboard 改了價），
 * 那種不一致會一路撐到使用者按下結帳才爆，所以要真的去問 Clink。
 */
class Verify extends Command
{
    protected ?string $signature = 'clink:verify';

    protected string $description = '檢查 Clink 的設定、product / price 對應、稅外加與 webhook 訂閱';

    private bool $ok = true;

    public function handle(): int
    {
        $client = new ClinkClient();

        $this->info(sprintf('環境：%s（%s）', $client->isTestMode() ? 'test' : 'live', $client->baseUrl()));

        $this->checkConfig($client);
        $this->checkCatalog($client);
        $this->checkWebhook($client);

        $this->newLine();

        if (!$this->ok) {
            $this->error('✗ 有項目未通過，見上方。');

            return self::FAILURE;
        }

        $this->info('✓ 全部通過。');

        return self::SUCCESS;
    }

    private function checkConfig(ClinkClient $client): void
    {
        $this->newLine();
        $this->line('設定：');

        foreach (['CLINK_API_KEY', 'CLINK_WEBHOOK_SECRET'] as $key) {
            (string) env($key, '') === ''
                ? $this->problem("  ✗ {$key} 沒有設定")
                : $this->line("  ✓ {$key} 已設定");
        }

        // test key 配 live base URL（或反過來）只會回 401，不會告訴你連錯環境。
        $apiKey = (string) env('CLINK_API_KEY', '');
        $expectedPrefix = $client->isTestMode() ? 'sk_uat_' : 'sk_prod_';

        if ($apiKey !== '' && !str_starts_with($apiKey, $expectedPrefix)) {
            $this->problem(sprintf(
                '  ✗ CLINK_API_KEY 不是 %s 開頭，與 CLINK_TEST_MODE=%s 不一致',
                $expectedPrefix,
                $client->isTestMode() ? 'true' : 'false'
            ));
        }

        // 前端回站頁靠網址上的參數分派金流：Clink 會附加 sessionId，這個網址本身
        // 不能帶 Stripe 的 session_id 或 Creem 的 checkout_id。
        $successUrl = (string) env('CLINK_SUCCESS_URL', '');

        if (!preg_match('#^https?://#i', $successUrl)) {
            $this->problem("  ✗ CLINK_SUCCESS_URL 必須是絕對網址，目前是「{$successUrl}」");
        } elseif (preg_match('/[?&](session_id|checkout_id)=/', $successUrl)) {
            $this->problem("  ✗ CLINK_SUCCESS_URL 不可自帶 session_id / checkout_id：{$successUrl}");
        } else {
            $this->line("  ✓ CLINK_SUCCESS_URL = {$successUrl}");
        }

        $configured = (string) env('PAYMENT_DEFAULT_PROVIDER', '');
        $shown = $configured === '' ? '(未設定)' : $configured;

        Subscription::defaultPaymentMethod() === Subscription::PAYMENT_METHOD_CLINK
            ? $this->line("  ✓ PAYMENT_DEFAULT_PROVIDER = {$shown}，實際生效 clink（新結帳會走 Clink）")
            : $this->warn("  ! PAYMENT_DEFAULT_PROVIDER = {$shown}，新的結帳不會走 Clink");
    }

    private function checkCatalog(ClinkClient $client): void
    {
        $this->newLine();
        $this->line('Product / Price 對應：');

        foreach (Price::with('plan')->get() as $price) {
            if ($price->stripeUnitAmount() <= 0 || !$price->plan) {
                continue;
            }

            $planLabel = "{$price->plan->title} {$price->unit}";

            /** @var null|Clink $product */
            $product = $price->plan->clink()->first();

            if (!$product || !$product->clink_id) {
                $this->problem("  ✗ {$price->plan->title} 沒有 Clink product，請跑 clink:sync");
            } else {
                $this->checkProduct($client, $product->clink_id);
            }

            foreach (Clink::VARIANTS as $variant) {
                /** @var null|Clink $row */
                $row = $price->clink->firstWhere('variant', $variant);

                if (!$row || !$row->clink_id) {
                    // 缺了哪一個都是死路：priceIdFor() 找不到對應就讓結帳失敗。
                    $this->problem("  ✗ {$planLabel} 缺少 {$variant} variant，請跑 clink:sync");

                    continue;
                }

                $this->checkPrice($client, $price, $variant, $row->clink_id, "{$planLabel}/{$variant}");
            }
        }
    }

    private function checkProduct(ClinkClient $client, string $productId): void
    {
        try {
            $product = $client->getProduct($productId);
        } catch (Throwable $e) {
            $this->problem("  ✗ product {$productId} 在 Clink 上查不到：{$e->getMessage()}");

            return;
        }

        if (($product['taxCategory'] ?? null) !== Sync::TAX_CATEGORY) {
            $this->problem(sprintf(
                '  ✗ product %s 的 taxCategory 是 %s，應為 %s',
                $productId,
                (string) ($product['taxCategory'] ?? '未設'),
                Sync::TAX_CATEGORY
            ));
        }
    }

    private function checkPrice(ClinkClient $client, Price $price, string $variant, string $priceId, string $label): void
    {
        try {
            $actual = $client->getPrice($priceId);
        } catch (Throwable $e) {
            $this->problem("  ✗ {$label} → {$priceId} 在 Clink 上查不到：{$e->getMessage()}");

            return;
        }

        $problems = Sync::priceProblems($price, $variant, $actual);

        // 稅外加錯掉不會讓任何東西報錯，只會讓歐盟客戶的實收少一截，所以要主動比對；
        // 確認不了也算失敗，不默默接受。
        if ($taxProblem = Sync::taxProblem($actual)) {
            $problems[] = $taxProblem;
        }

        $problems === []
            ? $this->line("  ✓ {$label} → {$priceId}")
            : $this->problem(sprintf('  ✗ %s → %s：%s', $label, $priceId, implode('、', $problems)));
    }

    /**
     * 少訂一個事件不會讓任何東西報錯，只會讓某種狀態變化永遠不進來。
     */
    private function checkWebhook(ClinkClient $client): void
    {
        $this->newLine();
        $this->line('Webhook：');

        $url = rtrim((string) config('app.url'), '/') . '/v1/webhook/clink';

        try {
            $endpoints = $client->listWebhookEndpoints();
        } catch (Throwable $e) {
            $this->problem("  ✗ 查不到 webhook endpoint：{$e->getMessage()}");

            return;
        }

        $endpoint = collect($endpoints)->first(
            fn (array $row) => ($row['url'] ?? null) === $url && ($row['enabled'] ?? false) === true
        );

        if (!$endpoint) {
            $this->problem("  ✗ 沒有啟用中的 endpoint 指向 {$url}");

            return;
        }

        $subscribed = is_array($endpoint['events'] ?? null) ? $endpoint['events'] : [];
        $missing = array_values(array_diff(ClinkController::HANDLED_EVENTS, $subscribed));

        $missing === []
            ? $this->line(sprintf('  ✓ %s 訂了全部 %d 個事件', $url, count(ClinkController::HANDLED_EVENTS)))
            : $this->problem(sprintf('  ✗ %s 少訂了：%s', $url, implode('、', $missing)));
    }

    private function problem(string $message): void
    {
        $this->error($message);
        $this->ok = false;
    }
}
