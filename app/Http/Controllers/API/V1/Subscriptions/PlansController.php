<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Subscriptions;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Schemas\PlanResource as PlanSchema;
use OpenApi\Attributes as OAT;

class PlansController
{
    #[OAT\Get(
        path: '/v1/plans',
        operationId: 'api.v1.plans.index',
        summary: 'List available subscription plans',
        tags: ['Plans'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'List of active subscription plans',
                content: new OAT\JsonContent(
                    type: 'array',
                    items: new OAT\Items(ref: PlanSchema::class)
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function index(): \Hypervel\Http\Resources\Json\AnonymousResourceCollection
    {
        $plans = Plan::query()->active()
            ->with(['prices'])
            ->orderBy('sort', 'asc')
            ->get();

        $plans = $plans->filter(function (Plan $plan) {
            return $plan->prices->isNotEmpty();
        });

        return PlanResource::collection($plans);
    }
}
