<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Plan;
use App\Models\Price;
use Hypervel\Http\Request;
use App\Models\Subscription;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Http\Resources\PlanResource;
use App\Services\SubscriptionService;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\Validators\SubscriptionValidator;
use App\Exceptions\InvalidRequestException;
use App\Services\PaddleSubscriptionService;
use App\Services\StripeSubscriptionService;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\PlanResource as PlanSchema;
use App\OpenApi\Parameters\Path\SubscriptionId as SubscriptionIdParam;

class SubscriptionsController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/subscriptions',
        operationId: 'api.v1.subscriptions.index',
        summary: "Get user's current subscription plan",
        security: [['bearerAuth' => []]],
        tags: ['Subscriptions'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Current subscription plan',
                content: new OAT\JsonContent(ref: PlanSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request, SubscriptionService $subscriptionService): PlanResource
    {
        $subscription = $subscriptionService->getUserSubscription($request->user()->id);
        $plan = $subscriptionService->getUserSubscriptionPlan($subscription);

        $plan->load([
            'prices' => function ($builder) use ($subscription) {
                $subscription
                    ? $builder->where('id', $subscription->price_id)
                    : $builder->where('unit', Price::UNIT_MONTHLY)->where('price', 0);
            },
        ]);

        return new PlanResource($plan);
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/subscriptions',
        operationId: 'api.v1.subscriptions.store',
        summary: 'Initiate a checkout for a subscription',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['planId', 'priceId'],
                properties: [
                    new OAT\Property(
                        property: 'planId',
                        description: 'Plan ID (ULID)',
                        type: 'string',
                        example: '01JCXYZ123456789ABCDEFGHIJ'
                    ),
                    new OAT\Property(
                        property: 'priceId',
                        description: 'Price ID (ULID)',
                        type: 'string',
                        example: '01JCXYZ123456789ABCDEFGHIJ'
                    ),
                    new OAT\Property(
                        property: 'paymentMethod',
                        description: 'Payment gateway (stripe or paddle, defaults to stripe)',
                        type: 'string',
                        enum: ['stripe', 'paddle'],
                        example: 'stripe'
                    ),
                ]
            )
        ),
        tags: ['Subscriptions'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Checkout initialization payload',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'stripe',
                            properties: [
                                new OAT\Property(property: 'publishable_key', type: 'string', example: 'pk_live_...'),
                                new OAT\Property(
                                    property: 'client_secret',
                                    description: 'Checkout Session client_secret for stripe.initEmbeddedCheckout()',
                                    type: 'string',
                                    example: 'cs_live_xxx_secret_xxx'
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        $params = $request->only(['planId', 'priceId', 'paymentMethod']);

        $v = new SubscriptionValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$plan = Plan::query()->find($params['planId'])) {
            throw new InvalidRequestException(['planId' => [__('validators.controllers.subscription.plan_not_found')]]);
        }

        if (!$price = Price::query()->find($params['priceId'])) {
            throw new InvalidRequestException(
                ['priceId' => [__('validators.controllers.subscription.price_not_found')]]
            );
        }

        if (!$plan->prices()->find($price->id)) {
            throw new InvalidRequestException(
                ['priceId' => [__('validators.controllers.subscription.price_not_in_plan')]]
            );
        }

        $paymentMethod = $params['paymentMethod'] ?? Subscription::PAYMENT_METHOD_STRIPE;

        $subscription = $request->user()->subscriptions()->create([
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => $paymentMethod,
            'status'         => Subscription::STATUS_PAYING,
        ]);

        if ($paymentMethod === Subscription::PAYMENT_METHOD_STRIPE) {
            $data = (new StripeSubscriptionService())->createCheckout(
                $request->user(),
                $plan,
                $price,
                $subscription
            );
        } else {
            $data = (new PaddleSubscriptionService())->createCheckout(
                $request->user(),
                $plan,
                $price,
                $subscription
            );
        }

        return response()->json($data);
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Put(
        path: '/v1/subscriptions/{subscriptionId}',
        operationId: 'api.v1.subscriptions.update',
        summary: 'Confirm subscription after successful Paddle payment',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['transaction_id'],
                properties: [
                    new OAT\Property(
                        property: 'transaction_id',
                        description: 'Paddle transaction ID',
                        type: 'string',
                        example: 'txn_01abc...'
                    ),
                ]
            )
        ),
        tags: ['Subscriptions'],
        parameters: [
            new OAT\Parameter(ref: SubscriptionIdParam::class),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'OK'),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function update(Request $request, string $subscriptionId)
    {
        if (!$subscription = $request->user()->subscriptions()->find($subscriptionId)) {
            throw new InvalidRequestException(
                ['subscriptionId' => [__('validators.controllers.subscription.not_found')]]
            );
        }

        $confirmed = (new PaddleSubscriptionService())->confirm(
            $subscription,
            $request->input('transaction_id', '')
        );

        if ($confirmed) {
            return response()->make(self::RESPONSE_OK);
        }
    }

    /**
     * 取消訂閱.
     */
    #[OAT\Delete(
        path: '/v1/subscriptions/{subscriptionId}',
        operationId: 'api.v1.subscriptions.destroy',
        summary: 'Cancel subscription',
        security: [['bearerAuth' => []]],
        tags: ['Subscriptions'],
        parameters: [
            new OAT\Parameter(ref: SubscriptionIdParam::class),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'OK'),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(response: 404, description: 'Subscription not found'),
        ]
    )]
    public function destroy(Request $request, SubscriptionService $subscriptionService)
    {
        if (!$subscription = $subscriptionService->getUserSubscription($request->user()->id)) {
            throw new NotFoundHttpException();
        }

        if ($subscription->payment_method === Subscription::PAYMENT_METHOD_STRIPE) {
            (new StripeSubscriptionService())->cancel($subscription);
        } else {
            (new PaddleSubscriptionService())->cancel($subscription);
        }

        return response()->make(self::RESPONSE_OK);
    }

    #[OAT\Get(
        path: '/v1/subscriptions/usage',
        operationId: 'api.v1.subscriptions.usage',
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
    #[OAT\Get(
        path: '/v1/subscriptions/checkout-session',
        operationId: 'api.v1.subscriptions.checkout-session',
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
                                new OAT\Property(property: 'period_start', type: 'string', format: 'date-time', example: '2026-05-30T00:00:00+00:00'),
                                new OAT\Property(property: 'period_end', type: 'string', format: 'date-time', example: '2026-06-30T00:00:00+00:00'),
                                new OAT\Property(property: 'amount', type: 'number', format: 'float', nullable: true, example: 9.99),
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
    public function checkoutSession(Request $request): ResponseInterface
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

    public function usage(Request $request, SubscriptionService $subscriptionService): ResponseInterface
    {
        $between = [
            'start' => now()->subDays(30)->startOfDay(),
            'end'   => now()->endOfDay(),
        ];

        $plan = $subscriptionService->getUserSubscriptionPlan(
            $subscriptionService->getUserSubscription($request->user()->id)
        );

        return response()->json([
            'data' => [
                'plan' => [
                    'channels' => $plan->channel_limit,
                    'media'    => $plan->video_limit,
                ],
                'usage' => [
                    'channels' => $request->user()->rss()->count(),
                    'media'    => $request->user()->media()->whereBetween('userables.created_at', $between)->count(),
                ],
            ],
        ]);
    }
}
