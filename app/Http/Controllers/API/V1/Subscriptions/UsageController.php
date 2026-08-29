<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Subscriptions;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\Services\ChatQuotaService;
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
        ChatQuotaService $chatQuotaService
    ): ResponseInterface {
        $plan = $subscriptionService->getUserSubscriptionPlan(
            $subscriptionService->getUserSubscription($request->user()->id)
        );

        $user = $request->user();
        $betweenDays = [now()->subDays(30)->startOfDay(), now()->endOfDay()];

        // chat 的週期跟 channels / media 不同：前兩者是總量與 30 天滾動窗口，
        // chat 是額度時區的自然日，所以額外附上重置時刻讓前端能講清楚。
        $chatQuota = $chatQuotaService->snapshot($user);

        return response()->json([
            'data' => [
                'plan' => [
                    'channels' => $plan->channel_limit,
                    'media'    => $plan->video_limit,
                    'chat'     => $chatQuota->limit,
                ],
                'usage' => [
                    'channels' => $user->sources()->count(),
                    'media'    => $user->media()
                        ->whereBetween('userables.created_at', $betweenDays)
                        ->count(),
                    'chat' => $chatQuota->used,
                ],
                'chat_reset_at' => $chatQuota->resetAt->getTimestamp(),
            ],
        ]);
    }
}
