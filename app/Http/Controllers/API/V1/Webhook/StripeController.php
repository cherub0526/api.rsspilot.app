<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use Stripe\Webhook;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\OpenApi\Responses\Http400;
use App\Exceptions\InvalidRequestException;
use App\Services\StripeSubscriptionService;
use App\Http\Controllers\AbstractController;
use Stripe\Exception\SignatureVerificationException;

class StripeController extends AbstractController
{
    #[OAT\Post(
        path: '/v1/webhook/stripe',
        operationId: 'api.v1.webhook.stripe.store',
        summary: 'Receive Stripe webhook events',
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
        $payload = (string) $request->getBody();
        $sigHeader = $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                env('STRIPE_WEBHOOK_SECRET')
            );
        } catch (SignatureVerificationException $e) {
            throw new InvalidRequestException(
                ['signature' => ['Invalid Stripe webhook signature.']]
            );
        }

        $eventData = $event->toArray();
        $service = new StripeSubscriptionService();

        match ($event->type) {
            'checkout.session.completed'    => $service->handleCheckoutSessionCompleted($eventData),
            'invoice.paid'                  => $service->handleInvoicePaid($eventData),
            'customer.subscription.deleted' => $service->handleSubscriptionDeleted($eventData),
            'invoice.payment_failed'        => $service->handleInvoicePaymentFailed($eventData),
            default                         => null,
        };

        return response()->make(self::RESPONSE_OK);
    }
}
