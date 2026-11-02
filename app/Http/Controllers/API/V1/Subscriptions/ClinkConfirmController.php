<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Subscriptions;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use Psr\Http\Message\ResponseInterface;
use App\Services\ClinkSubscriptionService;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class ClinkConfirmController extends AbstractController
{
    #[OAT\Post(
        path: '/v1/subscriptions/clink/confirm',
        operationId: 'api.v1.subscriptions.clink.confirm.store',
        summary: 'Activate a Clink subscription right after the customer returns from checkout',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['session_id'],
                properties: [
                    new OAT\Property(
                        property: 'session_id',
                        type: 'string',
                        example: 'sess_8Kq2LmN4pR7sT1vW',
                        description: 'The sessionId Clink appends to the success URL'
                    ),
                ]
            )
        ),
        tags: ['Subscriptions'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Subscription activated',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'subscription_id', type: 'string'),
                        new OAT\Property(property: 'status', type: 'string', example: 'trial'),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(response: 422, description: 'Session not found, not completed, or not yours'),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function store(Request $request): ResponseInterface
    {
        $sessionId = (string) $request->input('session_id', '');

        if ($sessionId === '') {
            throw new InvalidRequestException(
                ['session_id' => [__('validators.controllers.subscription.session_id_required')]]
            );
        }

        $subscription = (new ClinkSubscriptionService())->confirmCheckout(
            (string) $request->user()->id,
            $sessionId
        );

        // 查無、未完成、不屬於此使用者，一律同一則訊息，不透露是哪一種。
        // 前端遇到 422 會吞掉並重抓訂閱（webhook 可能已經補上）。
        if (!$subscription) {
            throw new InvalidRequestException(
                ['session_id' => [__('validators.controllers.subscription.checkout_not_confirmable')]]
            );
        }

        return response()->json([
            'subscription_id' => (string) $subscription->getKey(),
            'status'          => $subscription->status,
        ]);
    }
}
