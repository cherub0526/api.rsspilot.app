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

    /**
     * 試跑是付費功能，方案沒開通會擋在最前面。這個類別測的是它之後的行為，
     * 所以每個案例都先給一個開通的方案；擋下來的情形由 CustomPromptPlanGateTest 測。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::factory()->create([
            'title'                  => 'Pro',
            'custom_summary_enabled' => true,
            'sort'                   => 0,
        ]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);
    }

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
     * @param array<string, mixed> $summary
     */
    private function fakePreview(array $summary): void
    {
        $this->mock(SummaryPreviewService::class, function (MockInterface $mock) use ($summary) {
            $mock->shouldReceive('preview')->andReturn($summary);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return [
            'short_summary' => '一句話總結。',
            'long_summary'  => [
                'content'    => '完整的長摘要。',
                'key_points' => ['重點一'],
                'keywords'   => ['AI'],
            ],
        ];
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

    public function testStoreReturnsTheSameShapeAsStoredSummaries(): void
    {
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);

        $this->fakePreview($this->summary());

        // 與 summaries.text 同形狀，前端沿用既有的摘要渲染。
        $this->json('POST', $this->uri(), [
            'media_id' => $media->getKey(),
            'content'  => '請整理重點。',
        ])
            ->assertStatus(200)
            ->assertJsonPath('short_summary', '一句話總結。')
            ->assertJsonPath('long_summary.content', '完整的長摘要。')
            ->assertJsonPath('long_summary.keywords.0', 'AI');
    }

    public function testAModelOutsideThePlanIsIgnoredRatherThanRejected(): void
    {
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);

        $outside = AiModel::factory()->create();

        $this->fakePreview($this->summary());

        // 方案沒授權就退回系統預設模型，而不是把整次試跑擋掉。
        $this->json('POST', $this->uri(), [
            'media_id' => $media->getKey(),
            'content'  => '請整理重點。',
            'model_id' => $outside->getKey(),
        ])->assertStatus(200);
    }
}
