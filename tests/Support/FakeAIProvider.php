<?php

declare(strict_types=1);

namespace Tests\Support;

use Generator;
use RuntimeException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\MessageMapperInterface;

/**
 * 回傳固定內容的 NeuronAI provider 測試替身。
 *
 * NeuronAI 走自己建立的 Guzzle client，Hypervel 的 Http::fake() 攔不到，
 * 所以要在 provider 這層換掉才測得到。
 */
class FakeAIProvider implements AIProviderInterface
{
    public ?string $systemPrompt = null;

    /** @var Message[] 收到的訊息，供斷言送出的提示詞 */
    public array $received = [];

    public function __construct(private string $reply = '')
    {
    }

    public function chat(Message ...$messages): Message
    {
        $this->received = $messages;

        return new AssistantMessage($this->reply);
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    public function stream(Message ...$messages): Generator
    {
        throw new RuntimeException('這個替身只支援 chat()');
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        throw new RuntimeException('這個替身只支援 chat()');
    }

    public function messageMapper(): MessageMapperInterface
    {
        throw new RuntimeException('這個替身只支援 chat()');
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        throw new RuntimeException('這個替身只支援 chat()');
    }
}
