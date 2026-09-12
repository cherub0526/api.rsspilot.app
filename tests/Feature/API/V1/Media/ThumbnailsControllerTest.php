<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use DateTimeInterface;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Storage;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 播放器截圖。
 *
 * 這個資源沒有資料表——(mediaId, second) 直接推導出 S3 的 key，所以每個斷言的
 * 對象都是 S3 上的物件本身，而不是某張表的列。
 *
 * @internal
 * @coversNothing
 */
class ThumbnailsControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ────────────────────────────────────────────────

    /**
     * fake 的 s3 是本機磁碟，本身不會簽 URL，所以補一個可預測的簽發函式，
     * 讓測試能斷言「回的是這個 path 的連結」而不必真的去跟 AWS 要簽章。
     */
    private function fakeS3(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiration): string => 'https://signed.test/' . $path
        );
    }

    private function ownedMedia(User $user, array $attributes = []): Media
    {
        $media = Media::factory()->create($attributes);
        $user->media()->attach($media->id);

        return $media;
    }

    private function jpeg(int $kilobytes = 200): UploadedFile
    {
        return UploadedFile::fake()->image('frame.jpg', 1280, 720)->size($kilobytes);
    }

    private function path(Media $media, int $second): string
    {
        return sprintf('media/%s/thumbnails/%06d.jpg', $media->id, $second);
    }

    // ── show ───────────────────────────────────────────────────

    public function testShowRequiresAuthentication(): void
    {
        $this->fakeS3();

        $media = Media::factory()->create();
        $uri = route('api.v1.media.thumbnails.show', ['mediaId' => $media->id, 'second' => 125]);

        $this->json('GET', $uri)->assertStatus(401);
    }

    public function testShowReturnsNotFoundWhenNothingCapturedYet(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $uri = route('api.v1.media.thumbnails.show', ['mediaId' => $media->id, 'second' => 125]);

        $this->json('GET', $uri)->assertStatus(404);
    }

    public function testShowReturnsSignedUrlWhenThumbnailExists(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        Storage::disk('s3')->put($this->path($media, 125), 'jpeg-bytes');

        $uri = route('api.v1.media.thumbnails.show', ['mediaId' => $media->id, 'second' => 125]);

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonPath('media_id', $media->id)
            ->assertJsonPath('second', 125)
            ->assertJsonPath('url', 'https://signed.test/' . $this->path($media, 125));
    }

    /** 截圖是跨使用者共用的，但前提仍是這位使用者本來就看得到這支影片。 */
    public function testShowReturnsNotFoundForInaccessibleMedia(): void
    {
        $this->fakeS3();

        $this->fakeLogin();
        $media = Media::factory()->create();

        Storage::disk('s3')->put($this->path($media, 125), 'jpeg-bytes');

        $uri = route('api.v1.media.thumbnails.show', ['mediaId' => $media->id, 'second' => 125]);

        $this->json('GET', $uri)->assertStatus(404);
    }

    // ── store ──────────────────────────────────────────────────

    public function testStoreRequiresAuthentication(): void
    {
        $this->fakeS3();

        $media = Media::factory()->create();
        $uri = route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]);

        $this->json('POST', $uri)->assertStatus(401);
    }

    public function testStoreRejectsNonJpeg(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $uri = route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]);
        $file = UploadedFile::fake()->create('frame.txt', 10, 'text/plain');

        $this->json('POST', $uri, ['file' => $file, 'second' => 125])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testStoreRejectsOversizedFile(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $uri = route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]);

        $this->json('POST', $uri, ['file' => $this->jpeg(3000), 'second' => 125])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testStoreRejectsSecondBeyondDuration(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 300]);

        $uri = route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]);

        $this->json('POST', $uri, ['file' => $this->jpeg(), 'second' => 301])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['second']]);

        Storage::disk('s3')->assertMissing($this->path($media, 301));
    }

    /** duration 預設是 0（還沒抓到片長），此時不該把使用者整個擋掉。 */
    public function testStoreAcceptsAnySecondWhenDurationUnknown(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 0]);

        $uri = route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]);

        $this->json('POST', $uri, ['file' => $this->jpeg(), 'second' => 9999])
            ->assertStatus(201);

        Storage::disk('s3')->assertExists($this->path($media, 9999));
    }

    public function testStoreWritesToZeroPaddedPath(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 600]);

        $uri = route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]);

        $this->json('POST', $uri, ['file' => $this->jpeg(), 'second' => 125])
            ->assertStatus(201)
            ->assertJsonPath('second', 125)
            ->assertJsonPath('url', 'https://signed.test/' . $this->path($media, 125));

        Storage::disk('s3')->assertExists('media/' . $media->id . '/thumbnails/000125.jpg');
    }

    /**
     * 第二個人截同一秒時拿既有的那張，而且**不覆寫**——這張圖是所有人在那一秒
     * 共同看到的畫面，能被覆寫就等於能被替換掉。
     */
    public function testStoreReturnsExistingImageWithoutOverwriting(): void
    {
        $this->fakeS3();

        $owner = $this->fakeLogin();
        $media = $this->ownedMedia($owner, ['duration' => 600]);

        Storage::disk('s3')->put($this->path($media, 125), 'first-writer');

        $uri = route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]);

        $this->json('POST', $uri, ['file' => $this->jpeg(), 'second' => 125])
            ->assertStatus(200)
            ->assertJsonPath('url', 'https://signed.test/' . $this->path($media, 125));

        $this->assertSame('first-writer', Storage::disk('s3')->get($this->path($media, 125)));
    }

    public function testStoreReturnsNotFoundForInaccessibleMedia(): void
    {
        $this->fakeS3();

        $this->fakeLogin();
        $media = Media::factory()->create(['duration' => 600]);

        $uri = route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]);

        $this->json('POST', $uri, ['file' => $this->jpeg(), 'second' => 125])
            ->assertStatus(404);

        Storage::disk('s3')->assertMissing($this->path($media, 125));
    }
}
