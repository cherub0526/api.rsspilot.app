<?php

declare(strict_types=1);

namespace App\Console\Commands\Creem;

use Throwable;
use App\Models\Plan;
use App\Models\Creem;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\CreemClient;
use Hypervel\Console\Command;

/**
 * 依 `plans` / `prices` 在 Creem 建立對應的 product。
 *
 * **與 `paddle:sync` 的兩個結構差異：**
 *
 * 1. Creem 沒有 product → 多 price 的階層，價格直接綁在 product 上。所以這裡是
 *    **一個 price 對一個 product**，`plans` 只拿來組名稱，不會在 Creem 建東西。
 * 2. 每個付費 price 要建**兩個** product：含 30 天試用的、與不含的。原因見
 *    CreemSubscriptionService::productIdFor()——Creem 結帳時不能覆寫試用期，
 *    「首月免費只送第一次」只能用選 product 的方式守。
 *
 * 免費方案不會建 product：$0 不需要結帳。
 *
 * 這個指令是**只新增不修改**的。Creem 的 product 一旦有人用它訂閱過就不該再改價，
 * 所以已存在的 price 會直接跳過；要改價請在 Creem 建新 product，再把 `creems`
 * 的對應列指過去（與 Paddle 那邊「price 不可變」的處理原則一致）。
 */
class Sync extends Command
{
    /** 稅外加，與 Paddle 時期的 tax_mode=external 一致。理由見 syncVariant()。 */
    public const string TAX_MODE = 'exclusive';

    /** 我們賣的是 SaaS 訂閱。Creem 接受 saas / digital-goods-service / ebooks。 */
    public const string TAX_CATEGORY = 'saas';

    protected ?string $signature = 'creem:sync {--recreate : 捨棄現有對應並重建 product（product 屬性有變時用）}';

    protected string $description = '依現有 Plan / Price 在 Creem 建立對應的 Product（含試用與不含試用兩個變體）';

    public function handle(): void
    {
        $client = new CreemClient();

        $this->info(sprintf('環境：%s（%s）', $client->isTestMode() ? 'test' : 'live', $client->baseUrl()));

        if ($this->option('recreate') && !$this->confirmRecreate($client)) {
            return;
        }

        foreach (Plan::with('prices')->get() as $plan) {
            $this->info("處理方案：{$plan->title}");

            foreach ($plan->prices as $price) {
                $amount = $price->stripeUnitAmount();
                $label = "{$price->unit} \${$price->price}";

                if ($amount <= 0) {
                    $this->line("    - Price [{$label}] 是免費方案，不需要 Creem product");

                    continue;
                }

                foreach ([Creem::VARIANT_STANDARD, Creem::VARIANT_TRIAL] as $variant) {
                    $this->syncVariant($client, $plan, $price, $variant, $amount, $label);
                }
            }
        }

        $this->info('完成。');
    }

    /**
     * --recreate 會讓所有新結帳改用新建的 product。既有訂閱不受影響（它們綁在
     * 舊 product 上，繼續照原條件收費），但這仍然是會改變收費行為的動作，
     * 所以 live 要人親口確認。
     */
    private function confirmRecreate(CreemClient $client): bool
    {
        if ($client->isTestMode()) {
            $this->warn('--recreate：將捨棄現有 product 對應並重建（test 環境）。');

            return true;
        }

        $this->warn('--recreate 會在 **live** 建立新的 product，之後所有新結帳都改用新的。');
        $this->warn('既有訂閱不受影響，但舊 product 會留在帳號裡（不會刪除）。');

        return $this->confirm('確定要在 live 重建嗎？', false);
    }

    private function syncVariant(
        CreemClient $client,
        Plan $plan,
        Price $price,
        string $variant,
        int $amount,
        string $label
    ): void {
        $existing = $price->creem()->where('variant', $variant)->first();

        if ($existing && $this->option('recreate')) {
            // Creem 沒有 update product 的 API（與 Paddle 的 price 一樣，建了就定了），
            // 所以屬性要改只能建新的再把對應指過去。舊 product 留在 Creem 帳號裡不動
            // ——已經用它訂閱的人要繼續照原本的條件收費，刪掉會弄壞既有訂閱。
            $this->line("    · 捨棄舊對應 [{$label}/{$variant}]：{$existing->creem_id}");
            $existing->delete();
            $existing = null;
        }

        if ($existing && $existing->creem_id) {
            $this->line("    ✓ Price [{$label}/{$variant}] 已存在：{$existing->creem_id}");

            return;
        }

        $payload = [
            'name'           => $this->nameFor($plan, $price, $variant),
            'description'    => (string) ($plan->description ?: $plan->title),
            'price'          => $amount,
            'currency'       => 'USD',
            'billing_type'   => 'recurring',
            'billing_period' => $this->billingPeriodFor($price),
            // **一定要明示，不能吃預設值。** Creem 的預設是 `inclusive`（稅內含），
            // 與 Paddle 的 `tax_mode=external` 相反：同樣標價 $12.99，inclusive 會把
            // 法國客人的 20% VAT 從我們的收入裡扣掉（實收約 $10.82），exclusive 才是
            // 外加（客人付 $15.59、我們實收 $12.99）。
            //
            // 選 exclusive 是為了與 Paddle 時期一致——定價頁寫的 US$12.99 與
            // docs/pricing-cost-model 的毛利試算都是以「稅外加」為前提算的，改成內含
            // 等於歐盟客戶的單位營收少一截，而且不會有任何地方報錯。
            'tax_mode' => self::TAX_MODE,
            // 不設的話由 Creem 自己猜類別，稅率可能算錯。
            'tax_category' => self::TAX_CATEGORY,
        ];

        if ($variant === Creem::VARIANT_TRIAL) {
            // 一個月免費。長度的單一事實來源是 Subscription::FREE_MONTHS，但 Creem
            // 只吃「天數」，所以這裡換算成 30 天——與 Paddle 的 1 個月會有 0–1 天
            // 誤差，那是 Creem 的 API 形狀造成的，不是我們選的。
            //
            // 不傳 trial_price：API 規定它必須 ≥100 且小於 price，傳 0 是不合法的，
            // 而「省略」本來就代表免費試用。
            $payload['trial_period_days'] = Subscription::FREE_MONTHS * 30;
        }

        try {
            $product = $client->createProduct($payload);
        } catch (Throwable $e) {
            $this->error("    ✗ 建立 Price [{$label}/{$variant}] 失敗：{$e->getMessage()}");

            return;
        }

        $productId = (string) ($product['id'] ?? '');

        if ($productId === '') {
            $this->error("    ✗ Creem 沒有回傳 product id [{$label}/{$variant}]");

            return;
        }

        $price->creem()->create([
            'foreign_type' => Price::class,
            'creem_id'     => $productId,
            'creem_detail' => $product,
            'variant'      => $variant,
        ]);

        $this->line("    ✓ 建立 Price [{$label}/{$variant}]：{$productId}");
    }

    /**
     * Creem 後台只看得到 product 名稱，所以把方案、週期與變體都寫進去——
     * 八個長得很像的 product 混在一張清單裡，沒有這個就分不出誰是誰。
     */
    private function nameFor(Plan $plan, Price $price, string $variant): string
    {
        $suffix = $variant === Creem::VARIANT_TRIAL ? ' (first month free)' : '';

        return sprintf('%s – %s%s', $plan->title, $price->unit, $suffix);
    }

    private function billingPeriodFor(Price $price): string
    {
        return match ($price->unit) {
            Price::UNIT_QUARTERLY => 'every-three-months',
            Price::UNIT_ANNUALLY  => 'every-year',
            default               => 'every-month',
        };
    }
}
