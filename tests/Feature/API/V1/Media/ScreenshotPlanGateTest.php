<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use DateTimeInterface;
use App\Models\Setting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Hypervel\Http\UploadedFile;
use Tests\Support\FakeChatStreamer;
use Hypervel\Support\Facades\Storage;
use App\Utils\AI\ChatStreamerInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 播放器截圖的方案門檻。
 *
 * 判準是 plans.screenshot_enabled——只有 Advance 為 true。閘門在兩處：
 * 上傳截圖，以及帶圖提問。真正的成本在後者（vision 推論明顯貴於純文字，而每日
 * chat 額度沒有為帶圖加權），所以只擋上傳是不夠的。
 *
 * 純文字提問與既有歷史裡的截圖都不受影響，這裡一併驗。
 *
 * @internal
 * @coversNothing
 */
class ScreenshotPlanGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建立「沒有訂閱時的預設方案」：SubscriptionService 是用「有一筆月費 0 的
     * 價格」認免費方案的，所以 fixture 必須長成那樣。
     */
    private function defaultPlan(bool $screenshotEnabled): Plan
    {
        $plan = Plan::factory()->create([
            'title'              => $screenshotEnabled ? 'Advance' : 'Free',
            'screenshot_enabled' => $screenshotEnabled,
            'sort'               => 0,
        ]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);

        return $plan;
    }

    private function fakeS3(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiration): string => 'https://signed.test/' . $path
        );
    }

    private function fakeStreamer(): FakeChatStreamer
    {
        $streamer = new FakeChatStreamer(['Hello']);
        $this->app->instance(ChatStreamerInterface::class, $streamer);

        return $streamer;
    }

    private function media(): Media
    {
        $source = Source::factory()->create(['free' => true]);

        return Media::factory()->create(['source_id' => $source->id, 'duration' => 600]);
    }

    private function path(Media $media, int $second, string $checksum): string
    {
        return sprintf('media/%s/thumbnails/%06d.%s.jpg', $media->id, $second, $checksum);
    }

    private function sum(string $seed = 'frame'): string
    {
        return hash('sha256', $seed);
    }

    private function postFrame(Media $media): object
    {
        $file = UploadedFile::fake()->image('frame.jpg', 1280, 720)->size(200);

        return $this->json(
            'POST',
            route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]),
            [
                'file'     => $file,
                'second'   => 125,
                'checksum' => hash_file('sha256', $file->getRealPath()),
            ]
        );
    }

    private function askWithImage(Media $media, string $checksum): object
    {
        return $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => '這一格在講什麼？',
                    'images'  => [['second' => 125, 'checksum' => $checksum]],
                ],
            ],
        ]);
    }

    // ── 上傳 ───────────────────────────────────────────────────

    public function testPlanWithoutScreenshotsCannotUpload(): void
    {
        $this->fakeS3();
        $this->defaultPlan(false);
        $this->fakeLogin();

        $this->postFrame($this->media())
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']]);

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function testAdvancePlanCanUploadScreenshots(): void
    {
        $this->fakeS3();
        $this->defaultPlan(true);
        $this->fakeLogin();

        $this->postFrame($this->media())->assertStatus(201);
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    /** 沒有任何方案時一律擋下：無從判斷權益的預設是不給。 */
    public function testNoPlanAtAllCannotUploadScreenshots(): void
    {
        $this->fakeS3();
        $this->fakeLogin();

        $this->postFrame($this->media())
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']]);
    }

    // ── 帶圖提問 ───────────────────────────────────────────────

    /**
     * 只擋上傳是不夠的：截圖是內容定址的共用物件，理論上能引用別人存過的同一張
     * 畫面直接問，而推論才是貴的那一段。
     */
    public function testPlanWithoutScreenshotsCannotAskWithAnExistingScreenshot(): void
    {
        $this->fakeS3();
        $this->defaultPlan(false);
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        Setting::create(['user_id' => $user->id, 'data' => ['ai' => ['language' => 'en']]]);
        $media = $this->media();
        $checksum = $this->sum();

        // 圖已經存在（別人截的），但這個方案仍不該能拿它來問。
        Storage::disk('s3')->put($this->path($media, 125, $checksum), 'bytes');

        $this->askWithImage($media, $checksum)
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']]);

        $this->assertSame(0, $streamer->calls);
        $this->assertSame(0, ChatSession::count());
    }

    public function testAdvancePlanCanAskWithAScreenshot(): void
    {
        $this->fakeS3();
        $this->defaultPlan(true);
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        Setting::create(['user_id' => $user->id, 'data' => ['ai' => ['language' => 'en']]]);
        $media = $this->media();
        $checksum = $this->sum();

        Storage::disk('s3')->put($this->path($media, 125, $checksum), 'bytes');

        $this->askWithImage($media, $checksum)->assertStatus(200);
        $this->assertSame(1, $streamer->calls);
    }

    /** 純文字提問不受影響——閘門只在真的帶了圖時才檢查。 */
    public function testPlanWithoutScreenshotsCanStillAskWithTextOnly(): void
    {
        $this->fakeS3();
        $this->defaultPlan(false);
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        Setting::create(['user_id' => $user->id, 'data' => ['ai' => ['language' => 'en']]]);
        $media = $this->media();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '這部影片在講什麼？']],
        ])->assertStatus(200);

        $this->assertSame(1, $streamer->calls);
    }

    // ── 權益要吐給前端 ─────────────────────────────────────────

    /**
     * 前端的 canScreenshot 讀的是 GET /v1/subscriptions 的 screenshot_enabled，
     * 少了這個欄位，鈕就永遠是鎖住的樣子。
     */
    public function testSubscriptionExposesTheScreenshotFlag(): void
    {
        $this->defaultPlan(true);
        $this->fakeLogin();

        $this->json('GET', route('api.v1.subscriptions.index'))
            ->assertStatus(200)
            ->assertJsonPath('screenshot_enabled', true);
    }

    public function testSubscriptionReportsTheFlagAsFalseOnFree(): void
    {
        $this->defaultPlan(false);
        $this->fakeLogin();

        $this->json('GET', route('api.v1.subscriptions.index'))
            ->assertStatus(200)
            ->assertJsonPath('screenshot_enabled', false);
    }

    // ── 歷史 ───────────────────────────────────────────────────

    /**
     * 降級之後回頭看舊對話仍該看得到圖：讓歷史破圖不是權益該有的表達方式。
     */
    public function testDowngradedUserStillSeesScreenshotsInHistory(): void
    {
        $this->fakeS3();
        $this->defaultPlan(false);

        $user = $this->fakeLogin();
        $media = $this->media();
        $checksum = $this->sum();

        Storage::disk('s3')->put($this->path($media, 125, $checksum), 'bytes');

        $session = ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
            'title'    => '舊對話',
        ]);
        ChatMessage::create([
            'session_id' => $session->id,
            'role'       => ChatMessage::ROLE_USER,
            'content'    => '這一格？',
            'parts'      => [
                ['type' => ChatMessage::PART_IMAGE, 'second' => 125, 'checksum' => $checksum],
                ['type' => ChatMessage::PART_TEXT, 'text' => '這一格？'],
            ],
            'created_at' => now(),
        ]);

        $this->json('GET', route('api.v1.media.chat.sessions.show', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))
            ->assertStatus(200)
            ->assertJsonPath(
                'messages.0.parts.0.url',
                'https://signed.test/' . $this->path($media, 125, $checksum)
            );
    }
}
