<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use DateTimeInterface;
use Hypervel\Http\UploadedFile;
use Hyperf\Testing\Http\TestResponse;
use Hypervel\Support\Facades\Storage;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 播放器截圖。
 *
 * 這個資源沒有資料表——(mediaId, second, checksum) 直接推導出 S3 的 key，所以每個
 * 斷言的對象都是 S3 上的物件本身，而不是某張表的列。識別畫面的是 checksum：
 * 一秒有 24–60 幀，同一秒的不同畫面必須能並存。
 *
 * @internal
 * @coversNothing
 */
class ThumbnailsControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 截圖是 Advance 方案的功能（plans.screenshot_enabled），所以每個案例都需要一個
     * 有開通的方案，否則會先被方案閘門擋在 422。
     *
     * 沒有訂閱時的預設方案是「有一筆月費 0 的價格」的那一筆，fixture 因此要長成
     * 那樣。閘門本身的行為在 ScreenshotPlanGateTest 驗。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::factory()->create([
            'title'              => 'Advance',
            'screenshot_enabled' => true,
            'sort'               => 0,
        ]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);
    }

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

    /** 每次都產生內容不同的圖，才測得出「同一秒的不同幀」。 */
    private function jpeg(int $kilobytes = 200, int $width = 1280): UploadedFile
    {
        return UploadedFile::fake()->image('frame.jpg', $width, 720)->size($kilobytes);
    }

    private function checksumOf(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    private function path(Media $media, int $second, string $checksum): string
    {
        return sprintf('media/%s/thumbnails/%06d.%s.jpg', $media->id, $second, $checksum);
    }

    private function signed(Media $media, int $second, string $checksum): string
    {
        return 'https://signed.test/' . $this->path($media, $second, $checksum);
    }

    /** 合法但不對應任何內容的 checksum，用在不需要真的比對的案例。 */
    private function anyChecksum(string $seed = 'seed'): string
    {
        return hash('sha256', $seed);
    }

    /** @return array{0: TestResponse, 1: string} */
    private function postFrame(Media $media, int $second, ?UploadedFile $file = null): array
    {
        $file ??= $this->jpeg();
        $checksum = $this->checksumOf($file);

        $response = $this->json(
            'POST',
            route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]),
            ['file' => $file, 'second' => $second, 'checksum' => $checksum]
        );

        return [$response, $checksum];
    }

    private function showUri(Media $media, int $second, string $checksum): string
    {
        return route('api.v1.media.thumbnails.show', [
            'mediaId'  => $media->id,
            'second'   => $second,
            'checksum' => $checksum,
        ]);
    }

    // ── show ───────────────────────────────────────────────────

    public function testShowRequiresAuthentication(): void
    {
        $this->fakeS3();

        $media = Media::factory()->create();

        $this->json('GET', $this->showUri($media, 125, $this->anyChecksum()))->assertStatus(401);
    }

    public function testShowReturnsNotFoundWhenThisFrameIsNotStored(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $this->json('GET', $this->showUri($media, 125, $this->anyChecksum()))->assertStatus(404);
    }

    public function testShowReturnsSignedUrlWhenFrameExists(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $checksum = $this->anyChecksum();

        Storage::disk('s3')->put($this->path($media, 125, $checksum), 'jpeg-bytes');

        $this->json('GET', $this->showUri($media, 125, $checksum))
            ->assertStatus(200)
            ->assertJsonPath('media_id', $media->id)
            ->assertJsonPath('second', 125)
            ->assertJsonPath('checksum', $checksum)
            ->assertJsonPath('url', $this->signed($media, 125, $checksum));
    }

    /** 同一秒但不同內容的 checksum 不該互相命中——這正是改成內容定址要解的問題。 */
    public function testShowDoesNotMatchADifferentFrameInTheSameSecond(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        Storage::disk('s3')->put($this->path($media, 125, $this->anyChecksum('a')), 'frame-a');

        $this->json('GET', $this->showUri($media, 125, $this->anyChecksum('b')))->assertStatus(404);
    }

    public function testShowReturnsNotFoundForInaccessibleMedia(): void
    {
        $this->fakeS3();

        $this->fakeLogin();
        $media = Media::factory()->create();
        $checksum = $this->anyChecksum();

        Storage::disk('s3')->put($this->path($media, 125, $checksum), 'jpeg-bytes');

        $this->json('GET', $this->showUri($media, 125, $checksum))->assertStatus(404);
    }

    public function testShowRejectsMalformedChecksum(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        // 路由 pattern 只收 64 位小寫 hex，其餘連 Controller 都到不了。
        $this->json('GET', "/v1/media/{$media->id}/thumbnails/125/not-a-hash")->assertStatus(404);
    }

    public function testShowRejectsNonNumericSecond(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $this->json('GET', "/v1/media/{$media->id}/thumbnails/abc/{$this->anyChecksum()}")
            ->assertStatus(404);
    }

    // ── store ──────────────────────────────────────────────────

    public function testStoreRequiresAuthentication(): void
    {
        $this->fakeS3();

        $media = Media::factory()->create();

        $this->json('POST', route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]))
            ->assertStatus(401);
    }

    public function testStoreWritesToContentAddressedPath(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 600]);

        [$response, $checksum] = $this->postFrame($media, 125);

        $response->assertStatus(201)
            ->assertJsonPath('second', 125)
            ->assertJsonPath('checksum', $checksum)
            ->assertJsonPath('url', $this->signed($media, 125, $checksum));

        Storage::disk('s3')->assertExists($this->path($media, 125, $checksum));
    }

    /**
     * 同一秒的兩張不同畫面要並存，不互相覆蓋。這是 B 方案存在的理由：
     * 秒級 key 會讓硬切前後的兩張圖互相頂替。
     */
    public function testTwoDifferentFramesInTheSameSecondCoexist(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 600]);

        [$first, $checksumA] = $this->postFrame($media, 125, $this->jpeg(200, 1280));
        [$second, $checksumB] = $this->postFrame($media, 125, $this->jpeg(200, 1278));

        $first->assertStatus(201);
        $second->assertStatus(201);

        $this->assertNotSame($checksumA, $checksumB);
        Storage::disk('s3')->assertExists($this->path($media, 125, $checksumA));
        Storage::disk('s3')->assertExists($this->path($media, 125, $checksumB));
        $this->assertCount(2, Storage::disk('s3')->allFiles());
    }

    /** 同樣的內容再送一次就回既有的 URL，不再寫一次。 */
    public function testStoreReturnsExistingUrlForIdenticalBytes(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 600]);

        [$first, $checksum] = $this->postFrame($media, 125);
        $first->assertStatus(201);

        // 同尺寸的 fake image 內容一致，所以第二次的 checksum 會是同一個。
        [$second, $again] = $this->postFrame($media, 125);

        $this->assertSame($checksum, $again);
        $second->assertStatus(200)
            ->assertJsonPath('url', $this->signed($media, 125, $checksum));

        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    /**
     * checksum 決定路徑，所以不能採信客戶端自己說的值——否則它可以把任意內容
     * 擺到任意 key 上，內容定址的保證就沒了。
     */
    public function testStoreRejectsChecksumThatDoesNotMatchTheFile(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 600]);

        $this->json('POST', route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]), [
            'file'     => $this->jpeg(),
            'second'   => 125,
            'checksum' => $this->anyChecksum('lying'),
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['checksum']]);

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function testStoreRequiresChecksum(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $this->json('POST', route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]), [
            'file'   => $this->jpeg(),
            'second' => 125,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['checksum']]);
    }

    public function testStoreRejectsMalformedChecksum(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $this->json('POST', route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]), [
            'file'   => $this->jpeg(),
            'second' => 125,
            // 大寫 hex 也不行：路徑的大小寫必須固定，否則同一份內容會有兩個 key。
            'checksum' => strtoupper($this->anyChecksum()),
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['checksum']]);
    }

    public function testStoreRejectsNonJpeg(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $file = UploadedFile::fake()->create('frame.txt', 10, 'text/plain');

        $this->json('POST', route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]), [
            'file'     => $file,
            'second'   => 125,
            'checksum' => $this->checksumOf($file),
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testStoreRejectsOversizedFile(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        [$response] = $this->postFrame($media, 125, $this->jpeg(3000));

        $response->assertStatus(422)->assertJsonStructure(['messages' => ['file']]);
    }

    public function testStoreRequiresSecond(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $file = $this->jpeg();

        $this->json('POST', route('api.v1.media.thumbnails.store', ['mediaId' => $media->id]), [
            'file'     => $file,
            'checksum' => $this->checksumOf($file),
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['second']]);
    }

    public function testStoreRejectsNegativeSecond(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        [$response] = $this->postFrame($media, -1);

        $response->assertStatus(422)->assertJsonStructure(['messages' => ['second']]);
    }

    public function testStoreRejectsSecondBeyondDuration(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 300]);

        [$response] = $this->postFrame($media, 301);

        $response->assertStatus(422)->assertJsonStructure(['messages' => ['second']]);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    /** 界線是包含的：最後一秒的畫面仍屬於這支影片。 */
    public function testStoreAcceptsTheLastSecondOfTheVideo(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 300]);

        [$response, $checksum] = $this->postFrame($media, 300);

        $response->assertStatus(201);
        Storage::disk('s3')->assertExists($this->path($media, 300, $checksum));
    }

    /**
     * 秒數的上界不只是「不超過片長」——GET 的路由只收 6 位數，所以寫得進去、
     * 取不回來的秒數必須在寫入時就被擋下。duration 為 0 時這是唯一的上界。
     */
    public function testStoreRejectsSecondBeyondAddressableRange(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 0]);

        [$response] = $this->postFrame($media, 1234567);

        $response->assertStatus(422)->assertJsonStructure(['messages' => ['second']]);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    /** duration 預設是 0（還沒抓到片長），此時不該把使用者整個擋掉。 */
    public function testStoreAcceptsAnySecondWhenDurationUnknown(): void
    {
        $this->fakeS3();

        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user, ['duration' => 0]);

        [$response, $checksum] = $this->postFrame($media, 9999);

        $response->assertStatus(201);
        Storage::disk('s3')->assertExists($this->path($media, 9999, $checksum));
    }

    public function testStoreReturnsNotFoundForInaccessibleMedia(): void
    {
        $this->fakeS3();

        $this->fakeLogin();
        $media = Media::factory()->create(['duration' => 600]);

        [$response] = $this->postFrame($media, 125);

        $response->assertStatus(404);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }
}
