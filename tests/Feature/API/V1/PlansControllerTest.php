<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\Price;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class PlansControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testIndexIsPublic(): void
    {
        $this->json('GET', route('api.v1.plans.index'))->assertStatus(200);
    }

    public function testIndexOnlyReturnsActivePlansWithPrices(): void
    {
        $active = Plan::withoutEvents(fn () => Plan::factory()->create([
            'status' => Plan::STATUS_ACTIVE,
            'sort'   => 1,
        ]));
        Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $active->id]));

        $inactive = Plan::withoutEvents(fn () => Plan::factory()->create([
            'status' => Plan::STATUS_INACTIVE,
            'sort'   => 2,
        ]));
        Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $inactive->id]));

        // Active plan with no prices should be excluded too.
        $activeNoPrice = Plan::withoutEvents(fn () => Plan::factory()->create([
            'status' => Plan::STATUS_ACTIVE,
            'sort'   => 3,
        ]));

        $response = $this->json('GET', route('api.v1.plans.index'))->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($activeNoPrice->id));
    }

    public function testIndexOrdersBySortAscending(): void
    {
        $second = Plan::withoutEvents(fn () => Plan::factory()->create(['status' => Plan::STATUS_ACTIVE, 'sort' => 2]));
        Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $second->id]));

        $first = Plan::withoutEvents(fn () => Plan::factory()->create(['status' => Plan::STATUS_ACTIVE, 'sort' => 1]));
        Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $first->id]));

        $response = $this->json('GET', route('api.v1.plans.index'))->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->values();
        $this->assertEquals($first->id, $ids->first());
    }
}
