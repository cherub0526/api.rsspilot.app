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

    /**
     * 定價頁要寫「新帳號前 N 天有 M 支影片」，天數與支數只能從這裡來——前端寫死
     * 的話，改了設定檔對外說法就跟實際行為不一樣。
     */
    public function testIndexExposesNewAccountVideoBoost(): void
    {
        $response = $this->json('GET', route('api.v1.plans.index'))->assertStatus(200);

        $this->assertSame(
            (int) config('subscription.video_boost.days'),
            $response->json('meta.new_account_video_boost.days')
        );
        $this->assertSame(
            (int) config('subscription.video_boost.limit'),
            $response->json('meta.new_account_video_boost.limit')
        );
    }
}
