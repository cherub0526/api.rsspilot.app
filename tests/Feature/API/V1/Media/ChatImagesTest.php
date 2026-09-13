<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use DateTimeInterface;
use App\Models\Setting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Tests\Support\FakeChatStreamer;
use Hypervel\Support\Facades\Storage;
use App\Utils\AI\ChatStreamerInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 帶截圖提問的完整鏈路：請求 → 存在性驗證 → 落庫成 image 片段 → 送進推論 → 讀回歷史。
 *
 * 圖片本身先由 /thumbnails 端點上傳，這裡的每個案例都直接把物件放進 fake 的 S3，
 * 等同於「使用者已經截過這一秒」。
 *
 * @internal
 * @coversNothing
 */
class ChatImagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 截圖是 Pro 以上的功能（plans.screenshot_enabled），所以每個案例都需要一個
     * 有開通的方案，否則會先被方案閘門擋在 422。
     *
     * 沒有訂閱時的預設方案是「有一筆月費 0 的價格」的那一筆，fixture 因此要長成
     * 那樣。閘門本身的行為在 ScreenshotPlanGateTest 驗。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::factory()->create([
            'title'              => 'Pro',
            'screenshot_enabled' => true,
            'sort'               => 0,
        ]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);
    }

    // ── helpers ────────────────────────────────────────────────

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

    private function freeMedia(array $attributes = []): Media
    {
        $source = Source::factory()->create(['free' => true]);

        return Media::factory()->create(array_merge(['source_id' => $source->id], $attributes));
    }

    private function createUserSetting(User $user): void
    {
        Setting::create([
            'user_id' => $user->id,
            'data'    => ['ai' => ['language' => 'en']],
        ]);
    }

    private function path(Media $media, int $second, string $checksum): string
    {
        return sprintf('media/%s/thumbnails/%06d.%s.jpg', $media->id, $second, $checksum);
    }

    /** 一張截圖的 checksum；以秒數當 seed 讓測試裡好對照。 */
    private function sum(int $second, string $variant = ''): string
    {
        return hash('sha256', "frame-{$second}-{$variant}");
    }

    /** 把這些畫面放進 fake S3，等同於「使用者已經截過」。 */
    private function captureAt(Media $media, int ...$seconds): void
    {
        foreach ($seconds as $second) {
            Storage::disk('s3')->put($this->path($media, $second, $this->sum($second)), 'bytes');
        }
    }

    private function signed(Media $media, int $second, string $variant = ''): string
    {
        return 'https://signed.test/' . $this->path($media, $second, $this->sum($second, $variant));
    }

    /**
     * 請求裡的 images 條目。
     *
     * @return array{second: int, checksum: string}
     */
    private function ref(int $second, string $variant = ''): array
    {
        return ['second' => $second, 'checksum' => $this->sum($second, $variant)];
    }

    // ── 送進推論 ───────────────────────────────────────────────

    public function testAttachedScreenshotsReachTheModelAsSignedUrls(): void
    {
        $this->fakeS3();
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();
        $this->captureAt($media, 125);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'user', 'content' => '這一格在講什麼？', 'images' => [$this->ref(125)]],
            ],
        ])->assertStatus(200);

        $this->assertSame([$this->signed($media, 125)], $streamer->imagesAt(0));
    }

    /** 沒附圖的回合不帶 images，推論層才會照原本的方式送純字串。 */
    public function testTextOnlyTurnsCarryNoImagesKey(): void
    {
        $this->fakeS3();
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '這部影片在講什麼？']],
        ])->assertStatus(200);

        $this->assertArrayNotHasKey('images', $streamer->messages[0]);
    }

    /**
     * 整個請求最多送 4 張，由新到舊取——對話愈長，照單全收要重付的圖片 token 愈多。
     */
    public function testOnlyTheFourMostRecentScreenshotsAreSent(): void
    {
        $this->fakeS3();
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();
        $this->captureAt($media, 10, 20, 30, 40, 50);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'user', 'content' => '第一問', 'images' => [$this->ref(10), $this->ref(20)]],
                ['role' => 'assistant', 'content' => '第一答'],
                ['role' => 'user', 'content' => '第二問', 'images' => [$this->ref(30), $this->ref(40), $this->ref(50)]],
            ],
        ])->assertStatus(200);

        // 最舊的 10 被擠掉，它那一則只剩文字。
        $this->assertSame([$this->signed($media, 20)], $streamer->imagesAt(0));
        $this->assertSame(
            [$this->signed($media, 30), $this->signed($media, 40), $this->signed($media, 50)],
            $streamer->imagesAt(2)
        );
    }

    /**
     * 連續的同角色訊息會被合併成一則（推論層要求嚴格 user / assistant 交替），
     * 截圖必須跟著文字一起併過去——合併後仍是同一個人連續說的話。
     */
    public function testConsecutiveUserTurnsMergeTheirScreenshots(): void
    {
        $this->fakeS3();
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();
        $this->captureAt($media, 10, 20);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'user', 'content' => '先看這格', 'images' => [$this->ref(10)]],
                ['role' => 'user', 'content' => '再看這格', 'images' => [$this->ref(20)]],
            ],
        ])->assertStatus(200);

        $this->assertCount(1, $streamer->messages);
        $this->assertSame(
            [$this->signed($media, 10), $this->signed($media, 20)],
            $streamer->imagesAt(0)
        );
    }

    /**
     * 同一秒的兩張不同畫面要能各自附上、各自送進推論。
     *
     * 這是內容定址的整個理由：硬切前後同屬一秒，秒級 key 會讓兩張圖互相頂替，
     * 使用者看著切換後的畫面、AI 卻收到切換前那張。
     */
    public function testTwoDifferentFramesInTheSameSecondBothReachTheModel(): void
    {
        $this->fakeS3();
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();

        Storage::disk('s3')->put($this->path($media, 125, $this->sum(125, 'a')), 'frame-a');
        Storage::disk('s3')->put($this->path($media, 125, $this->sum(125, 'b')), 'frame-b');

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => '切換前後差在哪？',
                    'images'  => [$this->ref(125, 'a'), $this->ref(125, 'b')],
                ],
            ],
        ])->assertStatus(200);

        $this->assertSame(
            [$this->signed($media, 125, 'a'), $this->signed($media, 125, 'b')],
            $streamer->imagesAt(0)
        );

        $message = ChatMessage::where('role', ChatMessage::ROLE_USER)->firstOrFail();
        $this->assertSame([
            ['type' => ChatMessage::PART_IMAGE, 'second' => 125, 'checksum' => $this->sum(125, 'a')],
            ['type' => ChatMessage::PART_IMAGE, 'second' => 125, 'checksum' => $this->sum(125, 'b')],
            ['type' => ChatMessage::PART_TEXT, 'text' => '切換前後差在哪？'],
        ], $message->contentParts());
    }

    /** checksum 對不上任何已存物件時整個請求擋下來，即使那一秒有別的畫面。 */
    public function testUnknownChecksumIsRejectedEvenWhenTheSecondHasOtherFrames(): void
    {
        $this->fakeS3();
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();

        Storage::disk('s3')->put($this->path($media, 125, $this->sum(125, 'a')), 'frame-a');

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'user', 'content' => '這格', 'images' => [$this->ref(125, 'nope')]],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['images']]);

        $this->assertSame(0, $streamer->calls);
    }

    /** 同一秒重複附上沒有意義，送進推論與落庫都只該留一張。 */
    public function testDuplicateSecondsInOneTurnAreDeduped(): void
    {
        $this->fakeS3();
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();
        $this->captureAt($media, 125);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'user', 'content' => '這格', 'images' => [$this->ref(125), $this->ref(125)]],
            ],
        ])->assertStatus(200);

        $this->assertSame([$this->signed($media, 125)], $streamer->imagesAt(0));

        $message = ChatMessage::where('role', ChatMessage::ROLE_USER)->firstOrFail();
        $this->assertSame([
            ['type' => ChatMessage::PART_IMAGE, 'second' => 125, 'checksum' => $this->sum(125)],
            ['type' => ChatMessage::PART_TEXT, 'text' => '這格'],
        ], $message->contentParts());
    }

    // ── 落庫 ───────────────────────────────────────────────────

    public function testScreenshotPartsArePersistedBeforeTheText(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();
        $this->captureAt($media, 60, 125);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'user', 'content' => '比較這兩格', 'images' => [$this->ref(60), $this->ref(125)]],
            ],
        ])->assertStatus(200);

        $message = ChatMessage::where('role', ChatMessage::ROLE_USER)->firstOrFail();

        $this->assertSame([
            ['type' => ChatMessage::PART_IMAGE, 'second' => 60, 'checksum' => $this->sum(60)],
            ['type' => ChatMessage::PART_IMAGE, 'second' => 125, 'checksum' => $this->sum(125)],
            ['type' => ChatMessage::PART_TEXT, 'text' => '比較這兩格'],
        ], $message->contentParts());

        // content 是文字投影，截圖不該滲進去。
        $this->assertSame('比較這兩格', $message->partsToText());
    }

    // ── 讀回歷史 ───────────────────────────────────────────────

    public function testSessionDetailSignsScreenshotUrls(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();
        $this->captureAt($media, 125);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '這一格？', 'images' => [$this->ref(125)]]],
        ])->assertStatus(200);

        $session = ChatSession::firstOrFail();

        $this->json('GET', route('api.v1.media.chat.sessions.show', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))
            ->assertStatus(200)
            ->assertJsonPath('messages.0.parts.0.type', ChatMessage::PART_IMAGE)
            ->assertJsonPath('messages.0.parts.0.second', 125)
            ->assertJsonPath('messages.0.parts.0.checksum', $this->sum(125))
            ->assertJsonPath('messages.0.parts.0.url', $this->signed($media, 125));
    }

    /** 對話列表也吐訊息片段，同樣要簽得出 URL（走的是另一個 Resource）。 */
    public function testUserSessionListSignsScreenshotUrls(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();
        $this->captureAt($media, 125);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '這一格？', 'images' => [$this->ref(125)]]],
        ])->assertStatus(200);

        $this->json('GET', route('api.v1.users.sessions.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.last_messages.0.parts.0.type', ChatMessage::PART_IMAGE)
            ->assertJsonPath('data.0.last_messages.0.parts.0.url', $this->signed($media, 125));
    }

    // ── 驗證 ───────────────────────────────────────────────────

    /**
     * 指到不存在的截圖 → 422，而且不該扣掉一次額度：請求本身有問題，
     * 不是使用者真的問了一次。
     */
    public function testMissingScreenshotIsRejectedWithoutConsumingQuota(): void
    {
        $this->fakeS3();
        $streamer = $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '這一格？', 'images' => [$this->ref(125)]]],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['images']]);

        $this->assertSame(0, $streamer->calls);
        $this->assertSame(0, ChatSession::count());
        $this->assertSame(0, ChatMessage::count());
    }

    public function testMoreThanFourScreenshotsInOneTurnIsRejected(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => '太多了',
                    'images'  => [
                        $this->ref(1), $this->ref(2), $this->ref(3), $this->ref(4), $this->ref(5),
                    ],
                ],
            ],
        ])->assertStatus(422);
    }

    public function testNegativeSecondIsRejected(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '壞資料', 'images' => [$this->ref(-1)]]],
        ])->assertStatus(422);
    }

    public function testNonIntegerSecondIsRejected(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();

        $user = $this->fakeLogin();
        $this->createUserSetting($user);
        $media = $this->freeMedia();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => '壞資料',
                    'images'  => [['second' => 'abc', 'checksum' => $this->sum(1)]],
                ],
            ],
        ])->assertStatus(422);
    }
}
