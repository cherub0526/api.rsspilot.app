<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use App\Utils\AI\Completion;
use App\Services\Prompts\TemplateFactory;
use App\Exceptions\InvalidRequestException;
use App\Services\Prompts\TemplateCompletionManager;

/**
 * 試跑一份摘要設定，把模型的回應轉成可直接渲染的段落。
 *
 * 獨立成 service 而不是留在 Controller，除了薄 Controller 的要求之外還有一個
 * 實際理由：Completion 是用 static make() 依 config 建的，留在 Controller 裡
 * 就沒有任何接縫可以在測試中替換，happy path 會永遠打不到。
 */
class SummaryPreviewService
{
    /**
     * @param string $providerModel 空字串代表依模板查系統預設（見 OpenRouterModels::for()）
     * @return array<string, mixed> 與 summaries.text 相同的結構
     * @throws InvalidRequestException
     */
    public function preview(string $prompt, string $captions, string $language, string $providerModel = ''): array
    {
        $template = TemplateFactory::create('customPrompt', [
            'system_prompt'    => $prompt,
            'user_prompt'      => $captions,
            'respond_language' => $language,
            'language'         => $language,
        ]);

        try {
            $manager = new TemplateCompletionManager(Completion::make(), $template);
            $response = $manager->complete('', $providerModel);
            $content = (string) ($response['choices'][0]['message']['content'] ?? '');
        } catch (Throwable) {
            // 連線失敗、逾時、供應商回錯——對使用者而言結果都是「這次試跑沒有結果」，
            // 收斂成同一則訊息，不把第三方的錯誤細節轉述出去。
            throw new InvalidRequestException($this->failure());
        }

        return $this->parseSummary($content);
    }

    /**
     * 把模型回應解析成摘要結構。
     *
     * 形狀刻意與 summaries.text 一致（short_summary + long_summary），試跑看到的
     * 東西就是之後真的存下來的東西；前端也能直接沿用既有的渲染。
     *
     * 模型可能沒照指示回 JSON——那是它的問題，不是呼叫端填錯了，但對使用者而言
     * 一樣是沒有結果，所以走同一則訊息而不是拋 500。
     *
     * 判準是「長短摘要至少有一個有內容」：key_points 與 keywords 是附加資訊，
     * 缺了不影響這次試跑能不能看。
     *
     * @return array<string, mixed>
     * @throws InvalidRequestException
     */
    public function parseSummary(string $content): array
    {
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new InvalidRequestException($this->failure());
        }

        $short = trim((string) ($decoded['short_summary'] ?? ''));
        $long = is_array($decoded['long_summary'] ?? null) ? $decoded['long_summary'] : [];
        $longContent = trim((string) ($long['content'] ?? ''));

        if ($short === '' && $longContent === '') {
            throw new InvalidRequestException($this->failure());
        }

        return [
            'short_summary' => $short,
            'long_summary'  => [
                'content'    => $longContent,
                'key_points' => $this->stringList($long['key_points'] ?? null),
                'keywords'   => $this->stringList($long['keywords'] ?? null),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn ($item): string => is_scalar($item) ? trim((string) $item) : '',
                $value
            ),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function failure(): array
    {
        return ['content' => [__('validators.controllers.custom_prompts.preview_failed')]];
    }
}
