<?php

declare(strict_types=1);

namespace App\Services;

use Stripe\StripeClient as Client;

class StripeClient
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(env('STRIPE_API_KEY'));
    }

    public function customers(): \Stripe\Service\CustomerService
    {
        return $this->client->customers;
    }

    public function products(): \Stripe\Service\ProductService
    {
        return $this->client->products;
    }

    public function prices(): \Stripe\Service\PriceService
    {
        return $this->client->prices;
    }

    public function subscriptions(): \Stripe\Service\SubscriptionService
    {
        return $this->client->subscriptions;
    }

    public function invoices(): \Stripe\Service\InvoiceService
    {
        return $this->client->invoices;
    }

    public function checkoutSessions(): \Stripe\Service\Checkout\SessionService
    {
        return $this->client->checkout->sessions;
    }
}
