<?php

declare(strict_types=1);

namespace App\Console\Commands\OpenRouter;

use Throwable;
use App\Models\AiModel;
use Hypervel\Console\Command;
use Hypervel\Support\Facades\Http;

/**
 * 同步 OpenRouter 的模型型錄到 ai_models。
 *
 * 同步的是「供應商說了算」的欄位：顯示名稱、價格、支不支援思考。
 * `enabled` 與方案授權（plan_ai_models）都是產品決定，同步一律不碰——
 * 下架的模型不該因為供應商還在提供就自己復活。
 *
 * 新模型一律 enabled = false，也不會掛到任何方案上。型錄有 300+ 個模型，
 * 全開會讓使用者的下拉選單變成一份無法選擇的清單；要開放哪些是人決定的。
 */
class SyncModels extends Command
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/models';

    /**
     * 每百萬 token。來源給的是每 token 的小數，那個單位沒有人在用。
     */
    private const PRICE_UNIT = 1_000_000;
    protected ?string $signature = 'openrouter:sync-models {--dry-run : 只列出差異，不寫入資料庫}';

    protected string $description = '同步 OpenRouter 模型型錄（名稱、價格、思考能力）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('dry-run 模式：只比對，不寫入。');
        }

        $models = $this->fetch();

        if ($models === null) {
            return 1;
        }

        $this->info('取得 ' . count($models) . ' 個模型。');

        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($models as $model) {
            $id = (string) ($model['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $seen[] = $id;
            $attributes = $this->attributesFrom($model);
            $existing = AiModel::query()->where('provider_model', $id)->first();

            if ($existing === null) {
                ++$created;
                $this->line("  + 新模型 {$id}（預設不開放，未掛任何方案）");

                if (!$dryRun) {
                    AiModel::create($attributes + [
                        'provider_model' => $id,
                        'enabled'        => false,
                        'sort'           => 0,
                    ]);
                }

                continue;
            }

            $changes = $this->changesFor($existing, $attributes);

            if ($changes === []) {
                continue;
            }

            ++$updated;
            $this->line("  ~ {$id}：" . implode('、', $changes));

            if (!$dryRun) {
                $existing->update($attributes);
            }
        }

        $retired = $this->retire($seen, $dryRun);

        $this->info("新增 {$created}、更新 {$updated}、下架 {$retired}。");

        return 0;
    }

    /**
     * @return null|array<int, array<string, mixed>> null 代表取不到，呼叫端應視為失敗
     */
    private function fetch(): ?array
    {
        try {
            $response = Http::timeout(30)->get(self::ENDPOINT);
        } catch (Throwable $e) {
            $this->error('無法連線 OpenRouter：' . $e->getMessage());

            return null;
        }

        if (!$response->successful()) {
            $this->error('OpenRouter 回應 ' . $response->status());

            return null;
        }

        $data = $response->json('data');

        if (!is_array($data) || $data === []) {
            $this->error('OpenRouter 回應沒有 data，或型錄是空的。');

            return null;
        }

        return $data;
    }

    /**
     * 從來源的一筆模型取出我們要存的欄位。
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private function attributesFrom(array $model): array
    {
        $pricing = is_array($model['pricing'] ?? null) ? $model['pricing'] : [];

        return [
            'name'              => $this->displayName($model),
            'supports_thinking' => $this->supportsThinking($model),
            'input_price'       => $this->price($pricing['prompt'] ?? null),
            'output_price'      => $this->price($pricing['completion'] ?? null),
            'synced_at'         => now(),
        ];
    }

    /**
     * 來源的 name 帶供應商前綴（"Anthropic: Claude Sonnet 5"）。
     * 前綴在 provider_model 裡已經有了，顯示名稱去掉比較乾淨。
     *
     * @param array<string, mixed> $model
     */
    private function displayName(array $model): string
    {
        $name = trim((string) ($model['name'] ?? $model['id'] ?? ''));
        $parts = explode(':', $name, 2);

        return trim($parts[1] ?? $parts[0]);
    }

    /**
     * 支援思考的判準是 supported_parameters 含 reasoning。
     *
     * 不看 include_reasoning：那是「回應要不要帶出思考內容」的開關，
     * 有些模型只宣告它而不接受 reasoning 參數，兩者不是同一件事。
     *
     * @param array<string, mixed> $model
     */
    private function supportsThinking(array $model): bool
    {
        $params = $model['supported_parameters'] ?? [];

        return is_array($params) && in_array('reasoning', $params, true);
    }

    /**
     * 每 token 換算成每百萬 token。取不到或非數值時回 null——0 會被誤讀成免費。
     */
    private function price(mixed $perToken): ?float
    {
        if (!is_numeric($perToken)) {
            return null;
        }

        return round((float) $perToken * self::PRICE_UNIT, 6);
    }

    /**
     * 比對出實際有變的欄位，只為了讓輸出說得出「改了什麼」。
     *
     * @param array<string, mixed> $attributes
     * @return array<int, string>
     */
    private function changesFor(AiModel $model, array $attributes): array
    {
        $changes = [];

        if ($model->getAttribute('name') !== $attributes['name']) {
            $changes[] = '名稱';
        }

        if ((bool) $model->getAttribute('supports_thinking') !== $attributes['supports_thinking']) {
            $changes[] = '思考能力';
        }

        foreach (['input_price' => '輸入價', 'output_price' => '輸出價'] as $column => $label) {
            $before = $model->getAttribute($column);
            $before = $before === null ? null : round((float) $before, 6);

            if ($before !== $attributes[$column]) {
                $changes[] = $label;
            }
        }

        return $changes;
    }

    /**
     * 型錄裡不再出現的模型一律停用而不是刪除。
     *
     * 刪掉的話，已經選了它的 custom_prompts 會指向不存在的 id；停用則會讓那些設定
     * 在下次儲存時靜默退回系統預設，是可以收拾的狀態。
     *
     * @param array<int, string> $seen
     */
    private function retire(array $seen, bool $dryRun): int
    {
        $query = AiModel::query()
            ->where('enabled', true)
            ->whereNotIn('provider_model', $seen);

        $retired = (clone $query)->count();

        if ($retired > 0) {
            foreach ((clone $query)->pluck('provider_model') as $providerModel) {
                $this->warn("  - 型錄已無 {$providerModel}，停用");
            }

            if (!$dryRun) {
                $query->update(['enabled' => false]);
            }
        }

        return $retired;
    }
}
