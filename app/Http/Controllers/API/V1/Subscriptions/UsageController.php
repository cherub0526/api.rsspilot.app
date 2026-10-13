<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Subscriptions;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\Services\ChatQuotaService;
use App\Services\VideoQuotaService;
use App\Services\SubscriptionService;
use Psr\Http\Message\ResponseInterface;
use App\Http\Controllers\AbstractController;

class UsageController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/subscriptions/usage',
        operationId: 'api.v1.subscriptions.usage.index',
        summary: 'Get subscription usage statistics for the past 30 days',
        security: [['bearerAuth' => []]],
        tags: ['Subscriptions'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Usage statistics',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            properties: [
                                new OAT\Property(
                                    property: 'plan',
                                    properties: [
                                        new OAT\Property(property: 'channels', type: 'integer', example: 10),
                                        new OAT\Property(property: 'media', type: 'integer', example: 100),
                                    ],
                                    type: 'object'
                                ),
                                new OAT\Property(
                                    property: 'usage',
                                    properties: [
                                        new OAT\Property(property: 'channels', type: 'integer', example: 3),
                                        new OAT\Property(property: 'media', type: 'integer', example: 42),
                                    ],
                                    type: 'object'
                                ),
                                new OAT\Property(
                                    property: 'media_boost',
                                    description: '新帳號影片額度加碼。plan.media 已經套用，這裡是給前端說明用的',
                                    properties: [
                                        new OAT\Property(property: 'active', type: 'boolean', example: true),
                                        new OAT\Property(property: 'limit', type: 'integer', example: 10),
                                        new OAT\Property(property: 'plan_limit', type: 'integer', example: 3),
                                        new OAT\Property(
                                            property: 'ends_at',
                                            type: 'integer',
                                            example: 1760000000,
                                            nullable: true
                                        ),
                                    ],
                                    type: 'object'
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(
        Request $request,
        SubscriptionService $subscriptionService,
        ChatQuotaService $chatQuotaService,
        VideoQuotaService $videoQuotaService
    ): ResponseInterface {
        $plan = $subscriptionService->getUserSubscriptionPlan(
            $subscriptionService->getUserSubscription($request->user()->id)
        );

        $user = $request->user();

        // chat 的週期跟 channels / media 不同：前兩者是總量與 30 天滾動窗口，
        // chat 是額度時區的自然日，所以額外附上重置時刻讓前端能講清楚。
        $chatQuota = $chatQuotaService->snapshot($user);

        // 影片的上限與用量一律由 VideoQuotaService 給，不再自己算窗口：這裡以前
        // 用 subDays(30)、擋閘用 subDays(29)，側欄因此會出現「4 / 3」這種畫面。
        $videoQuota = $videoQuotaService->snapshot($user);

        return response()->json([
            'data' => [
                'plan' => [
                    'channels' => $plan->channel_limit,
                    // 生效上限，含新帳號加碼——前端的進度條與「還剩幾支」都讀這個
                    // 數字，給方案原值會讓加碼期間顯示 10 / 3。加碼結束後會回到
                    // plans.video_limit，差額在 media_boost 裡講清楚。
                    'media' => $videoQuota->limit,
                    'chat'  => $chatQuota->limit,
                ],
                'usage' => [
                    'channels' => $user->sources()->count(),
                    'media'    => $videoQuota->used,
                    'chat'     => $chatQuota->used,
                ],
                'chat_reset_at' => $chatQuota->resetAt->getTimestamp(),
                'media_boost'   => $videoQuota->boostPayload(),
            ],
        ]);
    }
}
