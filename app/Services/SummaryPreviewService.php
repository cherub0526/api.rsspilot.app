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
     * @return array<int, array{heading: string, items: array<int, string>}>
     * @throws InvalidRequestException
     */
    public function preview(string $prompt, string $captions, string $language, string $providerModel = ''): array
    {
        $template = TemplateFactory::create('summary_preview', [
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

        return $this->parseSections($content);
    }

    /**
     * 把模型回應解析成段落。
     *
     * 模型可能沒照指示回 JSON——那是它的問題，不是呼叫端填錯了，但對使用者而言
     * 一樣是沒有結果，所以走同一則訊息而不是拋 500。
     *
     * 沒有任何項目的段落直接丟掉：一個只有標題、底下空白的區塊在畫面上像是壞了。
     *
     * @return array<int, array{heading: string, items: array<int, string>}>
     * @throws InvalidRequestException
     */
    public function parseSections(string $content): array
    {
        $decoded = json_decode($content, true);
        $sections = is_array($decoded) ? ($decoded['sections'] ?? null) : null;

        if (!is_array($sections)) {
            throw new InvalidRequestException($this->failure());
        }

        $parsed = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $items = array_values(array_filter(
                array_map(
                    static fn ($item): string => is_scalar($item) ? trim((string) $item) : '',
                    is_array($section['items'] ?? null) ? $section['items'] : []
                ),
                static fn (string $item): bool => $item !== ''
            ));

            if ($items === []) {
                continue;
            }

            $parsed[] = [
                'heading' => trim((string) ($section['heading'] ?? '')),
                'items'   => $items,
            ];
        }

        if ($parsed === []) {
            throw new InvalidRequestException($this->failure());
        }

        return $parsed;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function failure(): array
    {
        return ['content' => [__('validators.controllers.custom_prompts.preview_failed')]];
    }
}
