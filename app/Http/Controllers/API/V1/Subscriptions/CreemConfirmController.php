<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Subscriptions;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use Psr\Http\Message\ResponseInterface;
use App\Services\CreemSubscriptionService;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class CreemConfirmController extends AbstractController
{
    #[OAT\Post(
        path: '/v1/subscriptions/creem/confirm',
        operationId: 'api.v1.subscriptions.creem.confirm.store',
        summary: 'Activate a Creem subscription right after the customer returns from checkout',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['checkout_id'],
                properties: [
                    new OAT\Property(
                        property: 'checkout_id',
                        type: 'string',
                        example: 'ch_4l0N34kxo16AhRKUHFUuXr',
                        description: 'The checkout_id Creem appends to success_url'
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
            new OAT\Response(response: 422, description: 'Checkout not found, not completed, or not yours'),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function store(Request $request): ResponseInterface
    {
        $checkoutId = (string) $request->input('checkout_id', '');

        if ($checkoutId === '') {
            throw new InvalidRequestException(
                ['checkout_id' => [__('validators.controllers.subscription.checkout_id_required')]]
            );
        }

        $subscription = (new CreemSubscriptionService())->confirmCheckout(
            (string) $request->user()->id,
            $checkoutId
        );

        // 查無、未完成、不屬於此使用者，一律同一則訊息——不透露是哪一種。
        // 前端遇到這個仍會重抓訂閱（webhook 可能已經補上），不會卡住使用者。
        if (!$subscription) {
            throw new InvalidRequestException(
                ['checkout_id' => [__('validators.controllers.subscription.checkout_not_confirmable')]]
            );
        }

        return response()->json([
            'subscription_id' => (string) $subscription->getKey(),
            'status'          => $subscription->status,
        ]);
    }
}
