<?php

declare(strict_types=1);

namespace Tests\Unit\Services\FollowUpQuestions;

use Tests\TestCase;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\VideoTranscriber\VideoTranscriberClient;
use App\Services\FollowUpQuestions\VideoTranscriberFollowUpQuestions;

/**
 * @internal
 * @coversNothing
 */
class VideoTranscriberFollowUpQuestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.videotranscriber.secret_key', 'nc_test');
        config()->set('services.videotranscriber.email', 'test@example.com');
        config()->set('services.videotranscriber.password', 'secret');
        config()->set('services.videotranscriber.unauthorized_codes', []);
    }

    /**
     * 回應是 SSE，fragment 放在扁平的 `message` 欄位（不是 OpenAI 的
     * choices[0].delta.content）—— 見 SummaryStreamParser。
     */
    private function fakeEndpoint(string $reply): void
    {
        $chunks = '';

        foreach (mb_str_split($reply, 8) as $fragment) {
            $chunks .= 'data: ' . json_encode(['message' => $fragment], JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        Http::fake([
            'videotranscriber.ai/api/v1/prod-config*'         => Http::response(['code' => 100000, 'data' => []], 200),
            'videotranscriber.ai/api/v1/summary/completions*' => Http::response($chunks . "data: [DONE]\n\n", 200),
        ]);
    }

    public function testGenerateReturnsTheThreeParsedQuestions(): void
    {
        $this->fakeEndpoint("### 1. 均線怎麼用？\n### 2. 族群怎麼選？\n### 3. 停損怎麼設？");

        $questions = (new VideoTranscriberFollowUpQuestions(new VideoTranscriberClient()))
            ->generate('前一輪的回答內容');

        $this->assertSame(['均線怎麼用？', '族群怎麼選？', '停損怎麼設？'], $questions);
    }

    public function testGenerateSendsThePromptWithSelectedTexts(): void
    {
        $this->fakeEndpoint('### 1. a');

        (new VideoTranscriberFollowUpQuestions(new VideoTranscriberClient()))
            ->generate('前一輪的回答內容');

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/summary/completions')) {
                return false;
            }

            $body = $request->data();

            $this->assertSame([], $body['selected_texts'] ?? null, 'follow-up 必須帶 selected_texts: []');
            $this->assertTrue($body['end_flag']);
            $this->assertTrue($body['streaming']);
            $this->assertSame(VideoTranscriberClient::SUMMARY_MODEL, $body['model']);
            $this->assertStringContainsString('generate three related follow-up questions', $body['text']);
            $this->assertStringEndsWith("**Answers:**\n前一輪的回答內容\n", $body['text']);

            return true;
        });
    }

    /**
     * selected_texts 是這條路徑專屬的。摘要與翻譯共用 completions()，
     * 不傳就不該出現在 body 裡，免得悄悄改變它們送出的內容。
     */
    public function testOtherCallersStillSendNoSelectedTexts(): void
    {
        $this->fakeEndpoint('summary');

        (new VideoTranscriberClient())->completions('the prompt');

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/summary/completions')) {
                return false;
            }

            $this->assertArrayNotHasKey('selected_texts', $request->data());

            return true;
        });
    }
}
