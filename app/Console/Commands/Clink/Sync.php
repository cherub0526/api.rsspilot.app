<?php

declare(strict_types=1);

namespace App\Console\Commands\Clink;

use Throwable;
use App\Models\Plan;
use App\Models\Clink;
use App\Models\Price;
use RuntimeException;
use App\Models\Subscription;
use App\Services\ClinkClient;
use Hypervel\Console\Command;

/**
 * 依 `plans` / `prices` 在 Clink 建立或修正對應的 product 與 price。
 *
 * - 每個有付費 price 的 Plan 對一個 Clink product（`taxCategory = software_service`）。
 * - 每個付費 Price 對兩個 Clink price：standard 與 trial。Clink 的試用期綁在 price 上、
 *   結帳時不可覆寫，「試用只送第一次」靠結帳時挑變體來守。
 * - 免費方案略過，$0 不需要結帳。
 *
 * 每次都從 Clink 的實際狀態算差異：缺的補、不對的用 `PUT /price/{id}` 修正，重跑幾次
 * 結果都一樣。Clink 改 price 產生新 snapshot，只影響新訂閱，既有訂閱維持原價，
 * 所以不需要 Creem 那個 `--recreate`。
 *
 * **稅外加無法經由 API 設定或讀回**（Clink 的 product / price 沒有任何稅務模式欄位）。
 * 使用者定案稅外加，所以這裡不默默接受：看不到 Clink 回報 exclusive 就報失敗，
 * exit code 為 1。要向 Clink 書面確認帳號層級的設定。
 */
class Sync extends Command
{
    public const string TAX_CATEGORY = 'software_service';

    public const string CURRENCY = 'USD';

    protected ?string $signature = 'clink:sync {--image= : 建立 product 用的圖片（預設 public/logo.png，即 app icon）}';

    protected string $description = '依現有 Plan / Price 在 Clink 建立或修正 product 與 price（每個付費 price 有 standard 與 trial 兩個變體）';

    private ?string $imageOssId = null;

    private bool $ok = true;

    public function handle(): int
    {
        $client = new ClinkClient();

        $this->info(sprintf('環境：%s（%s）', $client->isTestMode() ? 'test' : 'live', $client->baseUrl()));

        foreach (Plan::with('prices')->get() as $plan) {
            $paidPrices = $plan->prices->filter(fn (Price $price) => $price->stripeUnitAmount() > 0);

            if ($paidPrices->isEmpty()) {
                $this->line("略過免費方案：{$plan->title}");

                continue;
            }

            $this->info("處理方案：{$plan->title}");
            $productId = $this->syncProduct($client, $plan);

            if ($productId === null) {
                continue;
            }

            foreach ($paidPrices as $price) {
                foreach (Clink::VARIANTS as $variant) {
                    $this->syncPrice($client, $productId, $price, $variant);
                }
            }
        }

        if (!$this->ok) {
            $this->error('✗ 有項目未通過，見上方。');

            return self::FAILURE;
        }

        $this->info('完成。');

        return self::SUCCESS;
    }

    /**
     * 這個變體在 Clink 上應有的 price。`clink:verify` 拿同一份來比對。
     *
     * @return array<string, mixed>
     */
    public static function expectedPrice(Price $price, string $variant): array
    {
        return [
            'currency' => self::CURRENCY,
            // 主幣別：12.99 而不是 1299。prices.price 是 decimal 字串，轉 float 後
            // json_encode 輸出 12.99（serialize_precision = -1）。
            'unitAmount'       => (float) $price->price,
            'priceType'        => 'recurring',
            'recurringDetails' => array_filter([
                // quarter 由 Clink 解析，不換算成 3 個月。
                'interval' => match ($price->unit) {
                    Price::UNIT_ANNUALLY  => 'year',
                    Price::UNIT_QUARTERLY => 'quarter',
                    default               => 'month',
                },
                'intervalCount'   => 1,
                'pricingModel'    => 'flat_rate',
                'trialPeriodDays' => $variant === Clink::VARIANT_TRIAL ? Subscription::TRIAL_DAYS : null,
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * Clink 回傳的 price 與應有的值哪裡不同。空陣列代表一致。
     *
     * @param array<string, mixed> $actual GET /price/{id} 的 data
     * @return list<string>
     */
    public static function priceProblems(Price $price, string $variant, array $actual): array
    {
        $expected = self::expectedPrice($price, $variant);
        $recurring = is_array($actual['recurring'] ?? null) ? $actual['recurring'] : [];
        $problems = [];

        $amount = $actual['unitAmount'] ?? null;

        if (!is_numeric($amount) || round((float) $amount, 2) !== round($expected['unitAmount'], 2)) {
            $problems[] = sprintf('金額不符（我們 %s / Clink %s）', $expected['unitAmount'], is_scalar($amount) ? (string) $amount : '未設');
        }

        if (($actual['currency'] ?? null) !== self::CURRENCY) {
            $problems[] = sprintf('幣別是 %s，應為 USD', (string) ($actual['currency'] ?? '未設'));
        }

        if (($actual['priceType'] ?? null) !== 'recurring') {
            $problems[] = 'priceType 不是 recurring';
        }

        if (($recurring['interval'] ?? null) !== $expected['recurringDetails']['interval']) {
            $problems[] = sprintf(
                '週期是 %s，應為 %s',
                (string) ($recurring['interval'] ?? '未設'),
                $expected['recurringDetails']['interval']
            );
        }

        $trialDays = (int) ($recurring['trialPeriodDays'] ?? 0);

        if ($variant === Clink::VARIANT_STANDARD && $trialDays > 0) {
            // 最危險的方向：沒資格的人會拿到試用。
            $problems[] = "standard 變體卻有 {$trialDays} 天試用";
        }

        if ($variant === Clink::VARIANT_TRIAL && $trialDays !== Subscription::TRIAL_DAYS) {
            $problems[] = sprintf('trial 變體試用 %d 天，應為 %d 天', $trialDays, Subscription::TRIAL_DAYS);
        }

        if (($actual['active'] ?? null) === false) {
            $problems[] = '已停用（active = false）';
        }

        return $problems;
    }

    /**
     * 稅外加有沒有被 Clink 確認。null 代表確認為稅外加。
     *
     * Clink 的 OpenAPI 在 product / price 上沒有任何稅務模式欄位（2026-10 查證），
     * 所以現在一律回傳問題。這裡認得的欄位名是預留給 Clink 日後補上時用的，
     * 補上之後檢查會照實通過或失敗，而不是繼續靠人記得去問。
     *
     * @param array<string, mixed> $price GET /price/{id} 的 data
     */
    public static function taxProblem(array $price): ?string
    {
        foreach (['taxBehavior', 'taxMode', 'taxBehaviour'] as $field) {
            if (!isset($price[$field])) {
                continue;
            }

            return $price[$field] === 'exclusive'
                ? null
                : sprintf('Clink 回報 %s = %s，我們定案的是稅外加（exclusive）', $field, (string) $price[$field]);
        }

        if (array_key_exists('taxInclusive', $price)) {
            return $price['taxInclusive'] === false ? null : 'Clink 回報 taxInclusive = true（稅內含），我們定案的是稅外加';
        }

        return '無法確認稅外加：Clink API 沒有可設定或讀回稅務模式的欄位，需向 Clink 書面確認帳號為稅外加';
    }

    private function syncProduct(ClinkClient $client, Plan $plan): ?string
    {
        /** @var null|Clink $existing */
        $existing = $plan->clink()->first();

        if ($existing && $existing->clink_id) {
            try {
                $product = $client->getProduct($existing->clink_id);
            } catch (Throwable $e) {
                return $this->problem("  ✗ product {$existing->clink_id} 在 Clink 上查不到：{$e->getMessage()}");
            }

            if (($product['taxCategory'] ?? null) !== self::TAX_CATEGORY) {
                // API 沒有改 product 的端點，只能到 dashboard 改。
                $this->problem(sprintf(
                    '  ✗ product %s 的 taxCategory 是 %s，應為 %s（請到 Clink dashboard 修改）',
                    $existing->clink_id,
                    (string) ($product['taxCategory'] ?? '未設'),
                    self::TAX_CATEGORY
                ));
            } else {
                $this->line("  ✓ product 已存在：{$existing->clink_id}");
            }

            return $existing->clink_id;
        }

        try {
            $product = $client->createProduct([
                'name'        => $plan->title,
                'description' => (string) ($plan->description ?: $plan->title),
                'image'       => $this->imageOssId($client),
                'taxCategory' => self::TAX_CATEGORY,
            ]);
        } catch (Throwable $e) {
            return $this->problem("  ✗ 建立 product 失敗：{$e->getMessage()}");
        }

        $productId = $product['productId'] ?? null;

        if (!is_string($productId) || $productId === '') {
            return $this->problem('  ✗ Clink 沒有回傳 productId');
        }

        $plan->clink()->create([
            'foreign_type' => Plan::class,
            'clink_id'     => $productId,
            'clink_detail' => $product,
            'variant'      => Clink::VARIANT_STANDARD,
        ]);

        $this->line("  ✓ 建立 product：{$productId}");

        return $productId;
    }

    private function syncPrice(ClinkClient $client, string $productId, Price $price, string $variant): void
    {
        $label = "{$price->unit} \${$price->price}/{$variant}";
        $expected = self::expectedPrice($price, $variant);

        /** @var null|Clink $existing */
        $existing = $price->clink()->where('variant', $variant)->first();

        try {
            if (!$existing || !$existing->clink_id) {
                $created = $client->createPrice(['productId' => $productId, ...$expected]);
                $priceId = $created['priceId'] ?? null;

                if (!is_string($priceId) || $priceId === '') {
                    $this->problem("    ✗ [{$label}] Clink 沒有回傳 priceId");

                    return;
                }

                $price->clink()->create([
                    'foreign_type' => Price::class,
                    'clink_id'     => $priceId,
                    'clink_detail' => $created,
                    'variant'      => $variant,
                ]);

                $this->line("    ✓ 建立 price [{$label}]：{$priceId}");
                $this->checkTax($label, $created);

                return;
            }

            $actual = $client->getPrice($existing->clink_id);
            $problems = self::priceProblems($price, $variant, $actual);

            if ($problems === []) {
                $this->line("    ✓ price [{$label}] 一致：{$existing->clink_id}");
                $this->checkTax($label, $actual);

                return;
            }

            $this->warn(sprintf('    ! price [%s] %s：%s，修正中', $label, $existing->clink_id, implode('、', $problems)));

            // standard 要明送 0 才能把多出來的試用拿掉。
            $expected['recurringDetails']['trialPeriodDays'] ??= 0;
            $updated = $client->updatePrice($existing->clink_id, [...$expected, 'isActive' => true]);

            $existing->fill(['clink_detail' => $updated])->save();
            $this->line("    ✓ 已修正 price [{$label}]：{$existing->clink_id}");
            $this->checkTax($label, $updated);
        } catch (Throwable $e) {
            $this->problem(sprintf(
                '    ✗ price [%s] 同步失敗：%s（若是週期不符，PUT 可能不接受；請建新 price 並把 clinks 對應列指過去）',
                $label,
                $e->getMessage()
            ));
        }
    }

    /**
     * @param array<string, mixed> $price
     */
    private function checkTax(string $label, array $price): void
    {
        if ($problem = self::taxProblem($price)) {
            $this->problem("    ✗ price [{$label}] 稅外加：{$problem}");
        }
    }

    private function imageOssId(ClinkClient $client): string
    {
        if ($this->imageOssId !== null) {
            return $this->imageOssId;
        }

        $path = (string) ($this->option('image') ?: base_path('public/logo.png'));
        $uploaded = $client->uploadProductImage($path);
        $ossId = $uploaded['ossId'] ?? null;

        if (!is_string($ossId) || $ossId === '') {
            throw new RuntimeException('Clink did not return an ossId for the product image.');
        }

        return $this->imageOssId = $ossId;
    }

    private function problem(string $message): null
    {
        $this->error($message);
        $this->ok = false;

        return null;
    }
}
