<?php

declare(strict_types=1);

namespace App\Services;

use Stripe\Service\PriceService;
use Stripe\Service\InvoiceService;
use Stripe\Service\ProductService;
use Stripe\StripeClient as Client;
use Stripe\Service\CustomerService;
use Stripe\Service\SubscriptionService;
use Stripe\Service\Checkout\SessionService;

class StripeClient
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(env('STRIPE_API_KEY'));
    }

    public function customers(): CustomerService
    {
        return $this->client->customers;
    }

    public function products(): ProductService
    {
        return $this->client->products;
    }

    public function prices(): PriceService
    {
        return $this->client->prices;
    }

    public function subscriptions(): SubscriptionService
    {
        return $this->client->subscriptions;
    }

    public function invoices(): InvoiceService
    {
        return $this->client->invoices;
    }

    public function checkoutSessions(): SessionService
    {
        return $this->client->checkout->sessions;
    }
}
