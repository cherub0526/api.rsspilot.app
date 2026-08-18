<?php

declare(strict_types=1);

use App\Utils\AI\NeuronChatStreamer;
use App\Utils\AI\ChatStreamerInterface;
use App\Services\FollowUpQuestions\NeuronFollowUpQuestions;
use App\Services\FollowUpQuestions\FollowUpQuestionsGeneratorInterface;

return [
    ChatStreamerInterface::class => NeuronChatStreamer::class,

    // 延伸問題跟 chat 主流程用同一個推論來源（OpenRouter），品質與可用性才
    // 一致。另一個實作 VideoTranscriberFollowUpQuestions 走 videotranscriber.ai，
    // 保留是為了對照產出，不是預設。
    FollowUpQuestionsGeneratorInterface::class => NeuronFollowUpQuestions::class,
];
