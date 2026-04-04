<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use Carbon\Carbon;
use Hypervel\Http\Request;
use App\Models\Transaction;
use App\Models\Subscription;
use App\Services\PaddleClient;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\OpenApi\Responses\Http400;
use Paddle\SDK\Exceptions\ApiError;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Validators\PaddleTransactionValidator;
use Paddle\SDK\Entities\Shared\TransactionStatus;
use Paddle\SDK\Exceptions\SdkExceptions\MalformedResponse;

class PaddleController extends AbstractController
{
    #[OAT\Post(
        path: '/v1/webhook/paddle',
        operationId: 'api.v1.webhook.paddle.store',
        summary: 'Receive Paddle transaction webhook callback',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['event_id', 'event_type', 'occurred_at', 'notification_id', 'data'],
                properties: [
                    new OAT\Property(property: 'event_id', type: 'string', example: 'evt_01h8bzakzx3nhsf0rr6jh6vj6g'),
                    new OAT\Property(
                        property: 'event_type',
                        type: 'string',
                        enum: ['transaction.completed'],
                        example: 'transaction.completed'
                    ),
                    new OAT\Property(
                        property: 'occurred_at',
                        type: 'string',
                        format: 'date-time',
                        example: '2024-01-01T00:00:00Z'
                    ),
                    new OAT\Property(
                        property: 'notification_id',
                        type: 'string',
                        example: 'ntf_01h8bzakzx3nhsf0rr6jh6vj6g'
                    ),
                    new OAT\Property(
                        property: 'data',
                        required: ['id'],
                        properties: [
                            new OAT\Property(property: 'id', type: 'string', example: 'txn_01h8bzakzx3nhsf0rr6jh6vj6g'),
                        ],
                        type: 'object'
                    ),
                ]
            )
        ),
        tags: ['Webhook'],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function store(Request $request)
    {
        $params = $request->all();

        $v = new PaddleTransactionValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $paddleClient = new PaddleClient();

        try {
            $paddleTransaction = $paddleClient->transactions()->get($params['data']['id']);

            if ($paddleTransaction->status->getValue() !== TransactionStatus::Completed()->getValue()) {
                throw new InvalidRequestException(
                    ['transaction' => [__('validators.controllers.webhook.paddle.transaction_not_completed')]]
                );
            }

            if (!$subscription = Subscription::query()->find($paddleTransaction->customData->data['subscriptionId'])) {
                throw new InvalidRequestException(
                    ['subscription' => [__('validators.controllers.subscription.not_found')]]
                );
            }

            $paddleSubscription = $paddleClient->subscriptions()->get($paddleTransaction->subscriptionId);

            $subscription->fill([
                'start_date' => Carbon::parse($paddleSubscription->createdAt)->toDateTime(),
                'next_date'  => Carbon::parse($paddleSubscription->nextBilledAt)->toDateTime(),
                'status'     => Subscription::STATUS_ACTIVE,
            ])->save();

            if (!$subscription->paddle()->where(['paddle_id' => $paddleTransaction->subscriptionId])->first()) {
                $subscription->paddle()->create([
                    'paddle_id'     => $paddleSubscription->id,
                    'paddle_detail' => $paddleSubscription,
                    'foreign_type'  => Subscription::class,
                ]);
            }

            $transactionPaddle = $subscription->transactions()->whereHas(
                'paddle',
                function ($builder) use ($paddleTransaction) {
                    $builder->where('paddle_id', $paddleTransaction->id);
                }
            )->first();

            if (!$transactionPaddle) {
                $transactionPaddle = $subscription->transactions()->create([
                    'billing_date' => Carbon::parse($paddleTransaction->billedAt),
                    'amount'       => floatval($paddleTransaction->details->totals->total) / 100,
                    'status'       => TransactionStatus::Completed()->getValue(),
                ]);

                $transactionPaddle->paddle()->create([
                    'paddle_id'     => $paddleTransaction->id,
                    'paddle_detail' => $paddleTransaction,
                    'foreign_type'  => Transaction::class,
                ]);
            }

            return response()->make(self::RESPONSE_OK);
        } catch (ApiError $e) {
        } catch (MalformedResponse $e) {
        }
    }
}
