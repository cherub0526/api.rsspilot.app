<?php

declare(strict_types=1);

namespace App\Services\FollowUpQuestions;

use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * 透過 videotranscriber.ai 的 summary/completions 產生延伸問題。
 *
 * 那個端點是通用 completion，送什麼提示詞就做什麼事 —— 摘要與翻譯也走同一支，
 * 差別只在提示詞（見 SmartSummaryTemplate、TranslationTemplate）。
 *
 * `selected_texts: []` 是照著 web client 的請求帶的。client 預設不送這個欄位，
 * 這裡明確傳空陣列，讓送出的 body 與 web client 一致。
 */
class VideoTranscriberFollowUpQuestions implements FollowUpQuestionsGeneratorInterface
{
    public function __construct(
        protected VideoTranscriberClient $client,
        protected FollowUpQuestionsTemplate $template = new FollowUpQuestionsTemplate(),
        protected FollowUpQuestionsParser $parser = new FollowUpQuestionsParser(),
    ) {
    }

    public function generate(string $answers, string $language): array
    {
        return $this->parser->parse(
            $this->client->completions(
                text: $this->template->build($answers, $language),
                selectedTexts: [],
            )
        );
    }
}
