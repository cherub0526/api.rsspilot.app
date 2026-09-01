<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Subscriptions;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\Http\Resources\PlanResource;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\InvalidRequestException;
use App\Services\StripeSubscriptionService;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\PlanResource as PlanSchema;

class CheckoutSessionController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/subscriptions/checkout-session',
        operationId: 'api.v1.subscriptions.checkout-session.index',
        summary: 'Retrieve Stripe Checkout Session status after redirect',
        security: [['bearerAuth' => []]],
        tags: ['Subscriptions'],
        parameters: [
            new OAT\Parameter(
                name: 'session_id',
                in: 'query',
                required: true,
                description: 'Stripe Checkout Session ID',
                schema: new OAT\Schema(type: 'string', example: 'cs_live_xxx')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Session status with subscribed plan and billing info',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'status',
                            type: 'string',
                            enum: ['open', 'complete', 'expired'],
                            example: 'complete'
                        ),
                        new OAT\Property(
                            property: 'customer_email',
                            type: 'string',
                            nullable: true,
                            example: 'user@example.com'
                        ),
                        new OAT\Property(property: 'plan', ref: PlanSchema::class),
                        new OAT\Property(
                            property: 'billing',
                            type: 'object',
                            nullable: true,
                            description: 'Present only when status=complete',
                            properties: [
                                new OAT\Property(
                                    property: 'period_start',
                                    type: 'string',
                                    format: 'date-time',
                                    example: '2026-05-30T00:00:00+00:00'
                                ),
                                new OAT\Property(
                                    property: 'period_end',
                                    type: 'string',
                                    format: 'date-time',
                                    example: '2026-06-30T00:00:00+00:00'
                                ),
                                new OAT\Property(
                                    property: 'amount',
                                    type: 'number',
                                    format: 'float',
                                    nullable: true,
                                    example: 9.99
                                ),
                                new OAT\Property(property: 'currency', type: 'string', nullable: true, example: 'USD'),
                            ]
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(response: 404, description: 'Session not found or does not belong to user'),
        ]
    )]
    public function index(Request $request): ResponseInterface
    {
        $sessionId = (string) $request->input('session_id', '');

        if (!$sessionId) {
            throw new InvalidRequestException(
                ['session_id' => [__('validators.controllers.subscription.session_id_required')]]
            );
        }

        $result = (new StripeSubscriptionService())->retrieveCheckoutSession(
            $sessionId,
            $request->user()->id
        );

        return response()->json([
            'status'         => $result['status'],
            'customer_email' => $result['customer_email'],
            'plan'           => new PlanResource($result['plan']),
            'billing'        => $result['billing'],
        ]);
    }
}
