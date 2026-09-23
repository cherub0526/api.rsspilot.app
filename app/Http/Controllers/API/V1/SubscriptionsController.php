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
use App\Services\CreemSubscriptionService;
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
                description: 'Current subscription plan with status',
                content: new OAT\JsonContent(
                    allOf: [
                        new OAT\Schema(
                            properties: [
                                new OAT\Property(
                                    property: 'status',
                                    type: 'string',
                                    nullable: true,
                                    enum: ['trial', 'active'],
                                    example: 'trial',
                                    description: 'trial = on trial; active = paid; null = free plan'
                                ),
                                new OAT\Property(
                                    property: 'trial_ends_at',
                                    type: 'string',
                                    format: 'date-time',
                                    nullable: true,
                                    example: '2026-06-14T00:00:00+00:00',
                                    description: 'First billing date; present only when status=trial'
                                ),
                                new OAT\Property(
                                    property: 'first_month_free',
                                    type: 'boolean',
                                    example: true,
                                    description: 'true = this account has not used its one-off free first month yet'
                                ),
                            ]
                        ),
                        new OAT\Schema(ref: PlanSchema::class),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request, SubscriptionService $subscriptionService): ResponseInterface
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

        $status = $subscription?->status;

        // 免費月期間 status 是 trial，next_date 就是第一次扣款的日子。
        $trialEndsAt = ($status === Subscription::STATUS_TRIAL)
            ? $subscription->next_date?->toIso8601String()
            : null;

        return response()->json([
            'status'           => $status,
            'trial_ends_at'    => $trialEndsAt,
            'first_month_free' => $subscriptionService->isEligibleForFreeMonth($request->user()->id),
            ...(new PlanResource($plan))->toArray(),
        ]);
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
                        description: 'Payment gateway. Defaults to PAYMENT_DEFAULT_PROVIDER.',
                        type: 'string',
                        enum: ['stripe', 'paddle', 'creem'],
                        example: 'paddle'
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

        // 三條金流並存，用參數切換：
        //
        // 1. 請求帶 `paymentMethod` → 用它（前端可針對特定使用者或 A/B 指定）
        // 2. 沒帶 → 用 env 的 PAYMENT_DEFAULT_PROVIDER
        //
        // 預設放在 env 而不是寫死，是為了讓「整站換金流」變成改一個環境變數＋重啟，
        // 不必動前端也不必重新部署——Paddle 退件那次的教訓是，這個開關遲早要用。
        $paymentMethod = $params['paymentMethod'] ?? self::defaultPaymentMethod();

        $subscription = $request->user()->subscriptions()->create([
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => $paymentMethod,
            'status'         => Subscription::STATUS_PAYING,
        ]);

        $data = $this->checkoutServiceFor($paymentMethod)->createCheckout(
            $request->user(),
            $plan,
            $price,
            $subscription
        );

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

        // 依這筆訂閱**當初建立時**的金流分流，不是依現在的預設值——換了預設供應商
        // 之後，既有訂閱仍然要回到原本那家去取消。
        match ($subscription->payment_method) {
            Subscription::PAYMENT_METHOD_STRIPE => (new StripeSubscriptionService())->cancel($subscription),
            Subscription::PAYMENT_METHOD_CREEM  => (new CreemSubscriptionService())->cancel($subscription),
            default                             => (new PaddleSubscriptionService())->cancel($subscription),
        };

        return response()->make(self::RESPONSE_OK);
    }

    /**
     * 沒有指定 `paymentMethod` 時要用哪一家。
     *
     * 認不得的值退回 Paddle 而不是拋例外：這個變數打錯字的後果應該是「用回舊的」，
     * 不是「所有人都結不了帳」。
     */
    private static function defaultPaymentMethod(): string
    {
        $configured = (string) env('PAYMENT_DEFAULT_PROVIDER', Subscription::PAYMENT_METHOD_PADDLE);

        return in_array($configured, [
            Subscription::PAYMENT_METHOD_PADDLE,
            Subscription::PAYMENT_METHOD_STRIPE,
            Subscription::PAYMENT_METHOD_CREEM,
        ], true) ? $configured : Subscription::PAYMENT_METHOD_PADDLE;
    }

    /**
     * 建立結帳用的 service。三家的 createCheckout() 簽章刻意一致，
     * 所以這裡只挑物件，不做分支邏輯。
     */
    private function checkoutServiceFor(
        string $paymentMethod
    ): CreemSubscriptionService|PaddleSubscriptionService|StripeSubscriptionService {
        return match ($paymentMethod) {
            Subscription::PAYMENT_METHOD_STRIPE => new StripeSubscriptionService(),
            Subscription::PAYMENT_METHOD_CREEM  => new CreemSubscriptionService(),
            default                             => new PaddleSubscriptionService(),
        };
    }
}
