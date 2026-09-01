<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\CustomPrompts;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\AiModel;
use App\Models\Caption;
use Mockery\MockInterface;
use App\Services\SummaryPreviewService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * POST /v1/custom-prompts/preview.
 *
 * @internal
 * @coversNothing
 */
class PreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private function uri(): string
    {
        return route('api.v1.custom-prompts.preview.store');
    }

    /**
     * 使用者影片庫裡一支有主字幕的影片。
     */
    private function mediaWithCaption(User $user, string $text = '這部影片在講 AI 工作流程。'): Media
    {
        $media = Media::factory()->create();
        $user->media()->attach($media->getKey());

        Caption::factory()->create([
            'media_id' => $media->getKey(),
            'text'     => $text,
            'primary'  => true,
        ]);

        return $media;
    }

    /**
     * 推論一律 mock——測試不得對外發請求。
     *
     * mock 的是 service 而不是底層的 Completion：Completion 是用 static make()
     * 依 config 建的，沒有接縫可以替換。解析邏輯另由 SummaryPreviewServiceTest
     * 直接測。
     *
     * @param array<int, array<string, mixed>> $sections
     */
    private function fakePreview(array $sections): void
    {
        $this->mock(SummaryPreviewService::class, function (MockInterface $mock) use ($sections) {
            $mock->shouldReceive('preview')->andReturn($sections);
        });
    }

    public function testStoreRequiresAuthentication(): void
    {
        $this->json('POST', $this->uri(), ['media_id' => str_repeat('a', 26), 'content' => 'x'])
            ->assertStatus(401);
    }

    public function testStoreRejectsMissingFields(): void
    {
        $this->fakeLogin();

        $this->json('POST', $this->uri(), [])->assertStatus(422);
    }

    public function testStoreRejectsAMediaTheUserDoesNotOwn(): void
    {
        $this->fakeLogin();

        $foreign = Media::factory()->create();

        // 別人的影片連字幕都不該被拿去跑。
        $this->json('POST', $this->uri(), [
            'media_id' => $foreign->getKey(),
            'content'  => '請整理重點。',
        ])->assertStatus(422);
    }

    public function testStoreRejectsAMediaWithoutCaptions(): void
    {
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $user->media()->attach($media->getKey());

        // 字幕還沒好就沒有東西可摘要——擋下來，不要讓模型憑空編一份看起來像樣的。
        $this->json('POST', $this->uri(), [
            'media_id' => $media->getKey(),
            'content'  => '請整理重點。',
        ])->assertStatus(422);
    }

    public function testStoreReturnsParsedSections(): void
    {
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);

        $this->fakePreview([
            ['heading' => '主要論點', 'items' => ['第一點', '第二點']],
            ['heading' => '結論', 'items' => ['總結']],
        ]);

        $this->json('POST', $this->uri(), [
            'media_id' => $media->getKey(),
            'content'  => '請整理重點。',
        ])
            ->assertStatus(200)
            ->assertJsonCount(2, 'sections')
            ->assertJsonPath('sections.0.heading', '主要論點')
            ->assertJsonPath('sections.0.items.1', '第二點');
    }

    public function testAModelOutsideThePlanIsIgnoredRatherThanRejected(): void
    {
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);

        $outside = AiModel::factory()->create();
        $plan = Plan::factory()->create(['title' => 'Free', 'sort' => 0]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);

        $this->fakePreview([['heading' => 'x', 'items' => ['y']]]);

        // 方案沒授權就退回系統預設模型，而不是把整次試跑擋掉。
        $this->json('POST', $this->uri(), [
            'media_id' => $media->getKey(),
            'content'  => '請整理重點。',
            'model_id' => $outside->getKey(),
        ])->assertStatus(200);
    }
}
