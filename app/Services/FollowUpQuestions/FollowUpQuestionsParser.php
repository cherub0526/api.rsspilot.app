<?php

declare(strict_types=1);

namespace App\Services\FollowUpQuestions;

/**
 * 從模型回應中取出各個延伸問題。
 *
 * 獨立於提示詞之外，是因為目前有兩套提示詞共用同一個輸出格式：
 * 這個 namespace 下的 FollowUpQuestionsTemplate（只吃 answers），
 * 以及 App\Services\Prompts\FollowUpQuestionsTemplate（吃 question + answer）。
 * 兩者都要求 `### N. 問題` 的格式，解析只該有一份實作。
 */
class FollowUpQuestionsParser
{
    /**
     * 模型不保證乖乖給滿 3 題，也可能在前後多寫幾句、或改用不同層級的標題，
     * 所以逐行比對格式而不是整串硬切；解析到幾題就回幾題，由呼叫端決定
     * 不足時要怎麼辦。
     *
     * @return string[]
     */
    public function parse(string $response): array
    {
        if (preg_match_all('/^\s*#{1,6}\s*\d+\.\s*(.+?)\s*$/mu', $response, $matches) === 0) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), $matches[1]),
            static fn (string $question): bool => $question !== ''
        ));
    }
}
