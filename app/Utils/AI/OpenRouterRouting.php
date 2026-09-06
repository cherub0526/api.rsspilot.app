<?php

declare(strict_types=1);

namespace App\Utils\AI;

use App\Models\Config;

/**
 * 每個「用途」送給 OpenRouter 的額外請求參數（Auto Router 的 cost tier、價格上限、
 * 模型白／黑名單等）。
 *
 * 與 OpenRouterModels 是一組的：那邊決定 `model` 欄位，這邊決定同一個請求 body 裡
 * 其餘的路由設定。分成兩個 config key 而不是塞在一起，是因為兩者的生命週期不同——
 * 換模型是天天在試的事，改路由政策牽涉成本結構，動的頻率與審慎程度都不一樣。
 *
 * 值是**原封不動**傳給 OpenRouter 的（NeuronAI 的 HandleChat / HandleStream 都是
 * `...$this->parameters` 直接展開進 body，沒有白名單過濾）。刻意不在這裡定義型別化的
 * 結構：OpenRouter 的路由選項會變，寫死一份 schema 只會讓每次上游新增欄位都要改
 * 程式、重新部署，那正是 OpenRouterModels 當初把模型放進資料表要避免的事。
 *
 * 形狀範例（存在 configs 的 `openrouter_routing`）：
 *
 * ```json
 * {
 *   "App/Services/FollowUpQuestions/NeuronFollowUpQuestions": {
 *     "plugins": [{"id": "auto-router", "cost_tier": "low"}],
 *     "provider": {"max_price": {"prompt": 1, "completion": 4}}
 *   }
 * }
 * ```
 *
 * **`cost_tier` 不是成本上限。** 官方文件明講它是一個「價格帶」，比該帶便宜的模型
 * 也會被排除，所以把它調高有可能比釘死一個便宜模型還貴。真正的天花板是
 * `provider.max_price`（單位是每百萬 token 的美元價），要讓
 * `docs/lore/subscription/business-rules.md` 那套成本天花板算得出來，靠的是它。
 *
 * 沒設定就回空陣列 —— 完全不帶路由參數，行為與這個 class 出現之前一致。
 */
class OpenRouterRouting
{
    /** Auto Router 的 plugin id，cost_tier 掛在它底下。 */
    public const PLUGIN_AUTO_ROUTER = 'auto-router';

    /**
     * 由便宜到強的五個價格帶。沒有指定時 OpenRouter 大約以 low 路由。
     */
    public const TIER_LOW = 'low';

    public const TIER_MEDIUM = 'medium';

    public const TIER_HIGH = 'high';

    public const TIER_XHIGH = 'xhigh';

    public const TIER_MAX = 'max';

    /**
     * 取得指定用途要附加的請求參數。
     *
     * @param class-string|string $class
     * @return array<string, mixed> 可直接展開進 OpenRouter 的 request body
     */
    public static function for(string $class): array
    {
        $routing = Config::getValue(Config::KEY_OPENROUTER_ROUTING);

        if (!is_array($routing)) {
            return [];
        }

        $params = $routing[static::key($class)] ?? null;

        return is_array($params) ? $params : [];
    }

    /**
     * FQCN 轉成資料表用的 key，與 OpenRouterModels 同一套規則：
     * `App\Utils\AI\Foo` → `App/Utils/AI/Foo`。
     */
    protected static function key(string $class): string
    {
        return str_replace('\\', '/', ltrim($class, '\\'));
    }
}
