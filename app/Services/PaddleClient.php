<?php

declare(strict_types=1);

namespace App\Services;

use Paddle\SDK\Client;
use Paddle\SDK\Options;
use Paddle\SDK\Environment;
use Paddle\SDK\Resources\Prices\PricesClient;
use Paddle\SDK\Resources\Products\ProductsClient;
use Paddle\SDK\Resources\Customers\CustomersClient;
use Paddle\SDK\Resources\Transactions\TransactionsClient;
use Paddle\SDK\Resources\Subscriptions\SubscriptionsClient;

class PaddleClient
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(
            apiKey: env('PADDLE_API_KEY'),
            options: new Options(
                env('PADDLE_SANDBOX') ? Environment::SANDBOX : Environment::PRODUCTION
            )
        );
    }

    public function customers(): CustomersClient
    {
        return $this->client->customers;
    }

    public function products(): ProductsClient
    {
        return $this->client->products;
    }

    public function prices(): PricesClient
    {
        return $this->client->prices;
    }

    public function subscriptions(): SubscriptionsClient
    {
        return $this->client->subscriptions;
    }

    public function transactions(): TransactionsClient
    {
        return $this->client->transactions;
    }
}
