<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\Price;
use Hypervel\Http\Request;
use App\Models\Subscription;
use App\Services\PaddleClient;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\HttpOk;
use Paddle\SDK\Exceptions\ApiError;
use App\Http\Resources\PlanResource;
use App\Services\SubscriptionService;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\Validators\SubscriptionValidator;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use Paddle\SDK\Entities\Shared\TransactionStatus;
use App\OpenApi\Schemas\PlanResource as PlanSchema;
use Paddle\SDK\Exceptions\SdkExceptions\MalformedResponse;
use Paddle\SDK\Notifications\Entities\Payout\PayoutStatus;
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
        summary: 'Initiate a Paddle checkout for a subscription',
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
                ]
            )
        ),
        tags: ['Subscriptions'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Paddle checkout initialization payload',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'paddle',
                            properties: [
                                new OAT\Property(property: 'client_token', type: 'string', example: 'live_...'),
                                new OAT\Property(property: 'environment', type: 'string', enum: [
                                    'sandbox',
                                    'production',
                                ], example: 'production'),
                            ],
                            type: 'object'
                        ),
                        new OAT\Property(
                            property: 'items',
                            type: 'array',
                            items: new OAT\Items(type: 'string', example: 'pri_01abc...')
                        ),
                        new OAT\Property(
                            property: 'customer',
                            properties: [
                                new OAT\Property(property: 'name', type: 'string', example: 'John Doe'),
                                new OAT\Property(
                                    property: 'email',
                                    type: 'string',
                                    format: 'email',
                                    example: 'john@example.com'
                                ),
                                new OAT\Property(
                                    property: 'id',
                                    description: 'Paddle customer ID (present if user has an existing Paddle account)',
                                    type: 'string',
                                    example: 'ctm_01abc...'
                                ),
                            ],
                            type: 'object'
                        ),
                        new OAT\Property(
                            property: 'customData',
                            properties: [
                                new OAT\Property(
                                    property: 'subscriptionId',
                                    type: 'string',
                                    example: '01JCXYZ123456789ABCDEFGHIJ'
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
        $params = $request->only(['planId', 'priceId']);

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

        $subscription = $request->user()->subscriptions()->create([
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => Subscription::PAYMENT_METHOD_PADDLE,
            'status'         => Subscription::STATUS_PAYING,
        ]);

        $data = [
            'paddle' => [
                'client_token' => env('PADDLE_CLIENT_TOKEN'),
                'environment'  => env('PADDLE_SANDBOX') ? 'sandbox' : 'production',
            ],
            'items'    => [$price->paddle->paddle_id],
            'customer' => [
                'name'  => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'customData' => [
                'subscriptionId' => $subscription->id,
            ],
        ];

        if ($request->user()->paddle) {
            $data['customer']['id'] = $request->user()->paddle->paddle_customer_id;
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
            new OAT\Response(ref: Ok::class, response: 200),
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

        $params = $request->all();

        $paddle = new PaddleClient();
        try {
            $paddleTransaction = $paddle->transactions()->get($params['transaction_id']);

            if (
                $paddleTransaction->status->getValue() === PayoutStatus::Paid()->getValue()
                || $paddleTransaction->status->getValue() === TransactionStatus::Completed()->getValue()
            ) {
                $billedAt = Carbon::parse($paddleTransaction->billedAt);

                $items = $paddleTransaction->items;

                $subscription->fill([
                    'status'     => Subscription::STATUS_ACTIVE,
                    'start_date' => $billedAt->clone()->toDateTime(),
                    'next_date'  => $billedAt->clone()->add(
                        sprintf(
                            '%d %s',
                            $items[0]->price->billingCycle->frequency,
                            $items[0]->price->billingCycle->interval
                        )
                    ),
                ])->save();

                return response()->make(self::RESPONSE_OK);
            }
        } catch (ApiError $e) {
        } catch (MalformedResponse $e) {
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
            new OAT\Response(ref: Ok::class, response: 200),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(response: 404, description: 'Subscription not found'),
        ]
    )]
    public function destroy(Request $request, SubscriptionService $subscriptionService)
    {
        if (!$subscription = $subscriptionService->getUserSubscription($request->user()->id)) {
            throw new NotFoundHttpException();
        }

        $paddle = new PaddleClient();
        $paddle->subscriptions()->cancel($subscription->paddle->paddle_id);

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
                                        new OAT\Property(
                                            property: 'channels',
                                            description: 'Channel limit for the plan',
                                            type: 'integer',
                                            example: 10
                                        ),
                                        new OAT\Property(
                                            property: 'media',
                                            description: 'Video limit for the plan',
                                            type: 'integer',
                                            example: 100
                                        ),
                                    ],
                                    type: 'object'
                                ),
                                new OAT\Property(
                                    property: 'usage',
                                    properties: [
                                        new OAT\Property(
                                            property: 'channels',
                                            description: 'Number of subscribed RSS channels',
                                            type: 'integer',
                                            example: 3
                                        ),
                                        new OAT\Property(
                                            property: 'media',
                                            description: 'Number of videos added in the past 30 days',
                                            type: 'integer',
                                            example: 42
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
