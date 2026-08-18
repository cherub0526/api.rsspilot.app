<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media\Chat;

use App\Models\Summary;
use Hypervel\Http\Request;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\Services\ChatQuotaService;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Services\FollowUpQuestions\FollowUpQuestionsGeneratorInterface;

class FollowUpsController
{
    use ResolvesMedia;

    public function __construct(
        private FollowUpQuestionsGeneratorInterface $generator,
        private ChatQuotaService $quota,
    ) {
    }

    /**
     * GET /v1/media/{mediaId}/chat/sessions/{sessionId}/follow-ups.
     *
     * 依這個 session 最後一則 AI 回應產生延伸問題；還沒有任何 AI 回應時改用影片
     * 摘要當素材。刻意做成獨立端點而不是掛在 chat 回應裡：前端在答案顯示完之後
     * 才來要，主流程的延遲不受影響。
     *
     * 不計入每日 chat 額度——使用者只是看「可以接著問什麼」，還沒真的發問，
     * 光看建議就燒掉免費方案 3 次中的 1 次會讓功能沒人敢用。成本改由路由上的
     * throttle 中介層擋。但額度已經用盡時就不產生了：問題產出來也按不下去，
     * 只是白付一次推論的錢。
     *
     * @throws NotFoundHttpException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/chat/sessions/{sessionId}/follow-ups',
        operationId: 'api.v1.media.chat.sessions.followUps',
        summary: 'Generate follow-up questions from the last AI answer',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
            new OAT\Parameter(
                name: 'sessionId',
                description: 'Chat session ID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string', example: '01jsvgt3prpypqwex4wj78bznk')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Follow-up questions. Generated from the last AI answer, falling back to '
                    . 'the media summary when the session has no AI answer yet. Empty when neither is '
                    . 'available, when the daily chat quota is already used up, or when the model did '
                    . 'not respond in the expected format.',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            type: 'array',
                            items: new OAT\Items(type: 'string'),
                            example: [
                                'What makes this approach different from the alternatives?',
                                'How would this behave at a larger scale?',
                                'What are the trade-offs the video did not cover?',
                            ]
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function show(Request $request, string $mediaId, string $sessionId): ResponseInterface
    {
        $media = $this->resolveMedia($request, $mediaId);

        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', (string) $request->user()->getKey())
            ->where('media_id', $mediaId)
            ->first();

        if (!$session) {
            throw new NotFoundHttpException();
        }

        // 額度用完了就不產生。這個端點不扣額度，但問題產出來使用者也送不出去，
        // 等於白燒一次推論。回空陣列而不是 429——真正的 429 由 chat 端點負責，
        // 這裡只是安靜地不給建議。
        $snapshot = $this->quota->snapshot($request->user());

        if (!$snapshot->isUnlimited() && $snapshot->remaining() === 0) {
            return response()->json(['data' => []]);
        }

        $answer = ChatMessage::where('session_id', (string) $session->getKey())
            ->where('role', ChatMessage::ROLE_AI)
            ->orderByDesc('created_at')
            ->value('content');

        // 還沒有任何 AI 回應（session 剛開）就退回影片摘要當素材——這一刻使用者
        // 最想知道的正是「這支影片可以問什麼」。摘要取最新一份已完成的：重跑摘要
        // 會先建一筆 text 還空著的資料列，只看時間排序會蓋掉先前可用的那份。
        if (!is_string($answer) || $answer === '') {
            $summaryText = $media->summaries()
                ->where('status', Summary::STATUS_COMPLETED)
                ->orderByDesc('created_at')
                ->first()?->text ?? [];
            $answer = $summaryText['long_summary']['content'] ?? null;
        }

        // 連摘要都還沒好就真的沒有東西可以延伸。回空陣列而不是 404——session
        // 本身是存在的，只是這一刻沒有可用的素材。
        if (!is_string($answer) || $answer === '') {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->generator->generate($answer)]);
    }
}
