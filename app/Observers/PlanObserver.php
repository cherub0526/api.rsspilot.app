<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Plan;
use App\Services\PaddleClient;
use Paddle\SDK\Exceptions\ApiError;
use Paddle\SDK\Entities\Shared\TaxCategory;
use Paddle\SDK\Exceptions\ApiError\ProductApiError;
use Paddle\SDK\Exceptions\SdkExceptions\MalformedResponse;
use Paddle\SDK\Resources\Products\Operations\CreateProduct;
use Paddle\SDK\Resources\Products\Operations\UpdateProduct;

class PlanObserver
{
    /**
     * Handle the Plan "created" event.
     */
    public function created(Plan $plan): void
    {
        $paddle = new PaddleClient();

        try {
            $product = $paddle->products()->create(
                new CreateProduct(
                    name: $plan->title,
                    taxCategory: TaxCategory::Standard(),
                    description: $plan->description ?? ''
                )
            );

            $plan->paddle()->create([
                'foreign_type'  => Plan::class,
                'paddle_id'     => $product->id,
                'paddle_detail' => $product,
            ]);
        } catch (ProductApiError $e) {
        } catch (ApiError $e) {
        } catch (MalformedResponse $e) {
        }
    }

    /**
     * Handle the Plan "updated" event.
     */
    public function updated(Plan $plan): void
    {
        $paddle = new PaddleClient();

        try {
            $paddle->products()->update(
                $plan->paddle()->first()->paddle_id,
                new UpdateProduct(
                    name: $plan->title,
                    description: $plan->description ?? ''
                )
            );
        } catch (ProductApiError $e) {
        } catch (ApiError $e) {
        } catch (MalformedResponse $e) {
        }
    }
}
