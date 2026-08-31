<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Source;
use App\Models\AiModel;
use App\Models\CustomPrompt;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 自訂提示的模型與套用來源。
 *
 * @internal
 * @coversNothing
 */
class CustomPromptRelationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 沒有訂閱時的預設方案（月費 0），並把指定模型掛上去。
     */
    private function freePlanWith(array $models = []): Plan
    {
        $plan = Plan::factory()->create(['title' => 'Free', 'sort' => 0]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);

        if ($models !== []) {
            $plan->aiModels()->sync($models);
        }

        return $plan;
    }

    private function subscribedSource(User $user): Source
    {
        $source = Source::factory()->create();
        $user->sources()->attach($source->getKey());

        return $source;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'   => '學習筆記摘要',
            'content' => '請以學習筆記的風格整理重點。',
        ], $overrides);
    }

    public function testStorePersistsTheModelAndSources(): void
    {
        $user = $this->fakeLogin();
        $model = AiModel::factory()->create(['name' => 'Claude 3.5 Sonnet']);
        $this->freePlanWith([$model->getKey()]);
        $source = $this->subscribedSource($user);

        $response = $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload([
            'model_id'   => $model->getKey(),
            'source_ids' => [$source->getKey()],
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('model.id', (string) $model->getKey())
            ->assertJsonPath('model.name', 'Claude 3.5 Sonnet')
            ->assertJsonPath('sources.0.id', (string) $source->getKey());

        // provider_model 是內部路由用的代號，不該出現在對外輸出裡。
        $this->assertStringNotContainsString('provider_model', $response->getContent());
    }

    public function testStoreDropsSourcesTheUserDoesNotSubscribeTo(): void
    {
        $this->fakeLogin();
        $foreign = Source::factory()->create();

        $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload([
            'source_ids' => [$foreign->getKey()],
        ]))->assertStatus(201)->assertJsonPath('sources', []);
    }

    public function testStoreIgnoresAModelOutsideThePlan(): void
    {
        $this->fakeLogin();

        $outside = AiModel::factory()->create();
        $this->freePlanWith();

        // 選單不顯示它是不夠的——直接 POST 一個沒授權的 id 也要被擋。
        $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload([
            'model_id' => $outside->getKey(),
        ]))->assertStatus(201)->assertJsonPath('model', null);
    }

    public function testStoreIgnoresADisabledModel(): void
    {
        $this->fakeLogin();
        $model = AiModel::factory()->disabled()->create();
        $this->freePlanWith([$model->getKey()]);

        // 模型可能在使用者開著表單的期間被下架，那不是他填錯了——
        // 靜默退回不指定，而不是把整筆儲存擋掉。
        $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload([
            'model_id' => $model->getKey(),
        ]))->assertStatus(201)->assertJsonPath('model', null);
    }

    public function testIndexAndShowReturnTheRelations(): void
    {
        $user = $this->fakeLogin();
        $model = AiModel::factory()->create();
        $this->freePlanWith([$model->getKey()]);
        $source = $this->subscribedSource($user);

        $created = $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload([
            'model_id'   => $model->getKey(),
            'source_ids' => [$source->getKey()],
        ]))->json();

        $this->json('GET', route('api.v1.custom-prompts.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.model.id', (string) $model->getKey())
            ->assertJsonPath('data.0.sources.0.id', (string) $source->getKey());

        $this->json('GET', route('api.v1.custom-prompts.show', ['promptId' => $created['id']]))
            ->assertStatus(200)
            ->assertJsonPath('sources.0.id', (string) $source->getKey());
    }

    public function testUpdateReplacesTheSourcesInsteadOfAppending(): void
    {
        $user = $this->fakeLogin();
        $first = $this->subscribedSource($user);
        $second = $this->subscribedSource($user);

        $created = $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload([
            'source_ids' => [$first->getKey()],
        ]))->json();

        $response = $this->json('PUT', route('api.v1.custom-prompts.update', ['promptId' => $created['id']]), [
            'title'      => '改過的標題',
            'content'    => '改過的內容',
            'source_ids' => [$second->getKey()],
        ]);

        // PUT 是整筆取代：第一個來源要被換掉，不是累加。
        $response->assertStatus(200)->assertJsonCount(1, 'sources');
        $this->assertSame((string) $second->getKey(), $response->json('sources.0.id'));
    }

    public function testUpdateWithoutSourceIdsClearsThem(): void
    {
        $user = $this->fakeLogin();
        $source = $this->subscribedSource($user);

        $created = $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload([
            'source_ids' => [$source->getKey()],
        ]))->json();

        $this->json('PUT', route('api.v1.custom-prompts.update', ['promptId' => $created['id']]), [
            'title'   => '改過的標題',
            'content' => '改過的內容',
        ])->assertStatus(200)->assertJsonPath('sources', []);

        $this->assertSame(0, CustomPrompt::query()->find($created['id'])->sources()->count());
    }

    public function testStoreRejectsTooManySources(): void
    {
        $this->fakeLogin();

        $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload([
            'source_ids' => array_fill(0, 101, '01k9v7m2q8n4r6t0w3y5z7b1c9'),
        ]))->assertStatus(422);
    }
}
