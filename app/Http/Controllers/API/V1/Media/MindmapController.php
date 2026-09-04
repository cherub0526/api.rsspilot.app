<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Throwable;
use App\Models\User;
use App\Models\Media;
use App\Models\Mindmap;
use App\Models\Summary;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use Hypervel\Http\StreamOutput;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Responses\Http422;
use App\OpenApi\Responses\Http429;
use App\Utils\AI\OpenRouterModels;
use App\Services\DailyQuotaSnapshot;
use App\Utils\AI\NeuronChatStreamer;
use App\Services\MindmapQuotaService;
use App\Http\Resources\MindmapResource;
use App\Utils\AI\ChatStreamerInterface;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Services\Prompts\TemplateFactory;
use App\Exceptions\InvalidRequestException;
use App\Exceptions\MindmapQuotaExceededException;
use App\Http\Controllers\Concerns\SendsSseHeaders;
use App\Http\Controllers\API\V1\Media\Chat\ResolvesMedia;

/**
 * 心智圖：由既有摘要產生的多層 markdown 大綱，前端以 markmap 繪圖。
 *
 * 與對話不同，這裡不走「POST 觸發 + 常駐 GET 長連線」那套：心智圖是使用者主動
 * 觸發、只有一個消費者的一次性產物，POST 直接把 SSE 串回去就夠了，不必為它多養
 * 一條 Swoole 長連線。
 */
class MindmapController
{
    use ResolvesMedia;
    use SendsSseHeaders;

    public function __construct(
        private ChatStreamerInterface $streamer,
        private MindmapQuotaService $quota,
    ) {
    }

    /**
     * GET /v1/media/{mediaId}/mindmap.
     *
     * 取這位使用者在這支影片、這個 AI 語言下的心智圖。
     *
     * 沒有就是 404，不回空陣列——摘要端點回 `[]` 的做法逼得前端把型別寫成
     * `SummaryResource | []` 再用 Array.isArray() 分辨，不要再複製一次。
     *
     * @throws NotFoundHttpException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/mindmap',
        operationId: 'api.v1.media.mindmap.show',
        summary: 'Get the mind map for this media in the user AI language',
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Mind map',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'language', type: 'string', example: 'zh-TW'),
                        new OAT\Property(property: 'status', type: 'string', example: 'completed'),
                        new OAT\Property(property: 'markdown', type: 'string', nullable: true),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function show(Request $request, string $mediaId): ResponseInterface
    {
        $media = $this->resolveMedia($request, $mediaId);
        $user = $request->user();

        $summary = $media->summaryFor($user, true);

        if (!$summary instanceof Summary) {
            throw new NotFoundHttpException();
        }

        $mindmap = $this->findExisting($media, $summary, $user->aiLanguageCode());

        if (!$mindmap instanceof Mindmap) {
            throw new NotFoundHttpException();
        }

        return response()->json(new MindmapResource($mindmap));
    }

    /**
     * POST /v1/media/{mediaId}/mindmap.
     *
     * 產生（或重新產生）心智圖，回應本身就是 SSE 串流。
     * 事件格式與對話一致：connected / token / done / error。
     *
     * 命中既有心智圖時前端不會走到這裡（它先打 GET），所以這支端點一律扣一次額度。
     *
     * @throws NotFoundHttpException
     * @throws InvalidRequestException 摘要尚未完成，沒有東西可以拿來產生
     * @throws MindmapQuotaExceededException 當日心智圖額度已用盡
     */
    #[OAT\Post(
        path: '/v1/media/{mediaId}/mindmap',
        operationId: 'api.v1.media.mindmap.store',
        summary: 'Generate the mind map and stream it back over SSE',
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'SSE stream: connected / token / done / error events',
                content: new OAT\MediaType(
                    mediaType: 'text/event-stream',
                    schema: new OAT\Schema(
                        type: 'string',
                        example: "data: {\"type\":\"token\",\"token\":\"# \"}\n\ndata: {\"type\":\"done\"}\n\n"
                    )
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
            new OAT\Response(ref: Http422::class, response: 422),
            new OAT\Response(ref: Http429::class, response: 429),
        ]
    )]
    public function store(Request $request, string $mediaId): ResponseInterface
    {
        $media = $this->resolveMedia($request, $mediaId);
        $user = $request->user();

        $summary = $media->summaryFor($user, true);
        $input = $summary instanceof Summary ? $this->buildInput($summary) : '';

        // 心智圖的輸入是摘要而不是逐字稿，所以摘要沒完成就沒有東西可以產。
        // errorCode 讓前端能分支到「等摘要完成」的提示，而不是顯示一般錯誤。
        if ($input === '') {
            throw new InvalidRequestException(
                ['mindmap' => [__('validators.controllers.mindmap.summary_required')]],
                422,
                'summary_not_ready'
            );
        }

        $language = $user->aiLanguageCode();

        // 額度在建立資料列之前扣：被擋下來的請求不該留下 status=processing 的空殼。
        $quota = $this->quota->consume($user);

        $mindmap = $this->startRow($media, $summary, $language);

        $template = TemplateFactory::create('mindmap', ['language' => $user->aiLanguageName()]);
        $instructions = $template->getSystemPrompt();
        $userContent = trim($template->getUserPrompt() . "\n\n" . $input);

        return response()->stream(
            function (StreamOutput $output) use ($user, $quota, $mindmap, $instructions, $userContent): void {
                $this->generate($output, $user, $quota, $mindmap, $instructions, $userContent);
            },
            array_merge($this->sseHeaders($request), $quota->headers())
        );
    }

    /**
     * 跑推論、邊吐 SSE 邊累積，結束後落庫。
     *
     * 前端中途離開（切到對話分頁、關掉頁面）時 write() 會失敗，此時**只停止輸出、
     * 不停止推論**：快取是跨使用者共用的，產到一半丟掉等於錢燒了、額度扣了，下一
     * 個同語言的使用者還要再產一次。使用者切回來時 GET 就拿得到成品。
     */
    private function generate(
        StreamOutput $output,
        User $user,
        DailyQuotaSnapshot $quota,
        Mindmap $mindmap,
        string $instructions,
        string $userContent
    ): void {
        $buffer = '';
        $connected = $this->emit($output, true, ['type' => 'connected']);

        try {
            $stream = $this->streamer->stream($instructions, [
                ['role' => 'user', 'content' => $userContent],
            ]);

            foreach ($stream as $token) {
                // NeuronAI 在串流尾端會送出內容為空的 chunk，濾掉以免前端做無意義的重繪。
                if ($token === '') {
                    continue;
                }

                $buffer .= $token;
                $connected = $this->emit($output, $connected, ['type' => 'token', 'token' => $token]);
            }

            $mindmap->update([
                'markdown' => $buffer,
                'status'   => Mindmap::STATUS_COMPLETED,
            ]);

            $this->emit($output, $connected, ['type' => 'done']);
        } catch (Throwable $e) {
            $mindmap->update([
                'markdown' => $buffer === '' ? null : $buffer,
                'status'   => Mindmap::STATUS_FAILED,
            ]);

            // 一個 token 都沒拿到才退還額度。已經串出部分內容的話上游 token 也已經
            // 花掉了，那次算用掉——與對話的處理一致。
            if ($buffer === '') {
                $this->quota->release($user, $quota);
            }

            $this->emit($output, $connected, ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * 送出一個 SSE 事件，回傳連線是否仍然活著。
     *
     * 已經斷線就直接跳過寫入：Swoole 的 write() 對已關閉的連線每次都要試一遍。
     */
    private function emit(StreamOutput $output, bool $connected, array $payload): bool
    {
        if (!$connected) {
            return false;
        }

        return $output->write('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    /**
     * 把這次要寫入的資料列準備好。
     *
     * updateOrCreate 而不是 create：重新產生走的是同一支端點，而 (media_id,
     * summary_id, language) 上有唯一索引，直接 create 會撞索引。
     */
    private function startRow(Media $media, Summary $summary, string $language): Mindmap
    {
        return Mindmap::updateOrCreate(
            [
                'media_id'   => (string) $media->getKey(),
                'summary_id' => (string) $summary->getKey(),
                'language'   => $language,
            ],
            [
                'user_id'  => null,
                'markdown' => null,
                'status'   => Mindmap::STATUS_PROCESSING,
                'ai_model' => OpenRouterModels::for(NeuronChatStreamer::class),
            ]
        );
    }

    private function findExisting(Media $media, Summary $summary, string $language): ?Mindmap
    {
        return Mindmap::query()
            ->where('media_id', (string) $media->getKey())
            ->where('summary_id', (string) $summary->getKey())
            ->where('language', $language)
            ->first();
    }

    /**
     * 餵給模型的文字：摘要正文 + 重點條列 + 關鍵字。
     *
     * key_points 是現成的條列骨架，直接對應 ## / ### 的階層；只給 content 那段散文
     * 而要求四層結構，等於請模型編造。short_summary 刻意不放——它與 content 內容
     * 重複，會讓模型產出重複的分支。
     */
    private function buildInput(Summary $summary): string
    {
        $text = $summary->getAttribute('text');
        $text = is_array($text) ? $text : [];
        $long = is_array($text['long_summary'] ?? null) ? $text['long_summary'] : [];

        $parts = [];

        $content = trim((string) ($long['content'] ?? ''));
        if ($content !== '') {
            $parts[] = $content;
        }

        $keyPoints = array_filter(
            array_map('strval', is_array($long['key_points'] ?? null) ? $long['key_points'] : [])
        );
        if ($keyPoints !== []) {
            $parts[] = "Key points:\n- " . implode("\n- ", $keyPoints);
        }

        $keywords = array_filter(
            array_map('strval', is_array($long['keywords'] ?? null) ? $long['keywords'] : [])
        );
        if ($keywords !== []) {
            $parts[] = 'Keywords: ' . implode(', ', $keywords);
        }

        return trim(implode("\n\n", $parts));
    }
}
