<?php

declare(strict_types=1);

namespace App\Utils\AI;

use App\Models\User;
use App\Services\SubscriptionService;

/**
 * 一次推論實際要用的路由：模型 + 附加在請求 body 上的路由參數。
 *
 * 解析順序是「方案覆寫用途」：
 *
 * 1. **方案**（`plans.ai_routing`）—— 只有 per-user 的路徑才帶使用者進來
 * 2. **用途**（`configs.openrouter_models` / `configs.openrouter_routing`）
 *
 * 哪些路徑算 per-user 是產品決定，不是這裡決定的：chat、延伸問題與自訂摘要試跑
 * 是每位使用者各自產生、各自消費，吃方案；摘要與心智圖是**全站共用一列**
 * （`summaries.user_id` / `mindmaps.user_id` 都是 null），一支影片只產一次給所有人
 * 讀，沒有「當前使用者」可言，只吃用途層。詳見
 * docs/lore/prompts/business-rules.md〈方案覆寫用途，但共用產物只吃用途〉。
 *
 * 方案的 profile 可以只給 `model` 而不給參數（Free 就是這樣：`openrouter/free`
 * 不吃 auto-router 的 plugin），也可以只給參數而沿用用途層的模型。
 */
final class RoutingProfile
{
    /**
     * @param array<string, mixed> $parameters 直接展開進 OpenRouter request body
     */
    public function __construct(
        public readonly string $model,
        public readonly array $parameters = [],
    ) {
    }

    /**
     * 用途層設定。共用產物走這裡，per-user 路徑在方案沒覆寫時也退回這裡。
     *
     * @param class-string|string $class
     */
    public static function forPurpose(string $class): self
    {
        return new self(
            OpenRouterModels::for($class),
            OpenRouterRouting::for($class)
        );
    }

    /**
     * per-user 路徑用：方案有 `ai_routing` 就整組覆寫，沒有就退回用途層。
     *
     * $user 為 null 時等同 forPurpose()——沒有使用者就沒有方案可查，
     * 排程與 queue job 走的都是這條。
     *
     * @param class-string|string $class
     */
    public static function for(string $class, ?User $user = null): self
    {
        $purpose = self::forPurpose($class);

        if (!$user instanceof User) {
            return $purpose;
        }

        $routing = self::planRouting($user);

        if ($routing === null) {
            return $purpose;
        }

        $model = $routing['model'] ?? null;
        unset($routing['model']);

        return new self(
            is_string($model) && $model !== '' ? $model : $purpose->model,
            $routing
        );
    }

    /**
     * 兩組設定是不是同一件事。fallback 用它判斷「退回用途層」有沒有意義——
     * 方案本來就沒覆寫時再送一次一模一樣的請求，只是把同一個錯誤再吃一遍。
     */
    public function equals(self $other): bool
    {
        return $this->model === $other->model && $this->parameters === $other->parameters;
    }

    /**
     * 這位使用者的方案帶的路由設定。
     *
     * 查不到訂閱時 getUserSubscriptionPlan() 會退回「月費 0 元」的方案（也就是
     * Free），所以未訂閱的使用者一樣拿得到 profile，不會因為沒有訂閱就掉回用途層。
     *
     * @return null|array<string, mixed>
     */
    private static function planRouting(User $user): ?array
    {
        $subscriptions = app(SubscriptionService::class);

        $plan = $subscriptions->getUserSubscriptionPlan(
            $subscriptions->getUserSubscription((string) $user->getKey())
        );

        return $plan?->aiRouting();
    }
}
