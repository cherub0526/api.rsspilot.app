<?php

declare(strict_types=1);

namespace App\Utils\AI;

use App\Models\Config;
use App\Services\Prompts\SummaryTemplate;
use App\Services\Prompts\CustomPromptTemplate;
use App\Services\FollowUpQuestions\NeuronFollowUpQuestions;

/**
 * 每個「用途」各自用哪個 OpenRouter 模型。
 *
 * 對照表存在 configs 資料表的 `openrouter_models`，形狀是
 * `{"App/Utils/AI/NeuronChatStreamer": "openai/gpt-4.1-mini", ...}`。
 * key 用斜線而不是 PHP 的反斜線 —— JSON 裡不必逃逸成 `App\\Utils\\...`，
 * 直接讀資料表或後台人工改值時才看得懂。
 *
 * 放資料庫而不是 config 檔的原因：Swoole 是常駐程序，改 env / config 得整個
 * server 重啟才生效；模型是會頻繁試換的東西，不該綁在部署上。
 */
class OpenRouterModels
{
    /**
     * 每個用途對應的 class。
     *
     * chat 與延伸問題各自有專屬的推論 class，直接用它。summary 與 customPrompt
     * 共用 TemplateCompletionManager 這條路，所以改用**模板** class 當 key ——
     * 用途是模板決定的，掛在 manager 上兩者只會共用同一個模型，換不動其中一個。
     *
     * 新增用途時一併加進來，sync() 才知道要在資料表補哪個 key。Completion 不在
     * 這裡 —— 它只是 HTTP 傳輸層，模型由呼叫端決定。
     *
     * @var array<int, class-string>
     */
    public const CLASSES = [
        NeuronChatStreamer::class,
        NeuronFollowUpQuestions::class,
        SummaryTemplate::class,
        CustomPromptTemplate::class,
    ];

    /**
     * 取得指定 class 要用的模型。
     *
     * 資料表沒有對應 key（或值是空的）就退回 config('ai.default_model')，
     * 所以還沒登記進 CLASSES 的模板／class 不必等 sync() 補資料也能跑。
     *
     * @param class-string|string $class
     */
    public static function for(string $class): string
    {
        $models = Config::getValue(Config::KEY_OPENROUTER_MODELS);
        $model = is_array($models) ? ($models[static::key($class)] ?? null) : null;

        return (string) (is_string($model) && $model !== '' ? $model : config('ai.default_model'));
    }

    /**
     * CLASSES 的預設對照表，值一律是當下的 config('ai.default_model')。
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return array_fill_keys(
            array_map(static fn (string $class): string => static::key($class), static::CLASSES),
            (string) config('ai.default_model')
        );
    }

    /**
     * 讓資料表與 CLASSES 對齊：缺的 key 補上預設值，已存在的值原封不動
     * （線上調過的模型不該被重跑遷移或再次同步蓋回去），CLASSES 裡沒有的
     * key 則移除 —— 用途改名或下架後留著只會誤導讀表的人。
     *
     * @return array<string, string> 對齊後的完整對照表
     */
    public static function sync(): array
    {
        $current = Config::getValue(Config::KEY_OPENROUTER_MODELS);
        $current = is_array($current) ? $current : [];

        $defaults = static::defaults();
        $merged = array_merge($defaults, array_intersect_key($current, $defaults));

        Config::setValue(Config::KEY_OPENROUTER_MODELS, $merged);

        return $merged;
    }

    /**
     * FQCN 轉成資料表用的 key：`App\Utils\AI\Foo` → `App/Utils/AI/Foo`。
     */
    protected static function key(string $class): string
    {
        return str_replace('\\', '/', ltrim($class, '\\'));
    }
}
