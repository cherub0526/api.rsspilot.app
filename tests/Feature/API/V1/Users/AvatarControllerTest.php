<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Users;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserAvatar;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Storage;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class AvatarControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testUnauthenticatedUserCannotUploadAvatar(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');

        $this->json('POST', $uri)->assertStatus(401);
    }

    public function testUploadAvatarRequiresFile(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');
        $this->fakeLogin();

        $this->json('POST', $uri, [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testUploadAvatarValidatesFileType(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');
        $this->fakeLogin();

        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $this->json('POST', $uri, ['file' => $file])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testUploadAvatarValidatesFileSize(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');
        $this->fakeLogin();

        $file = UploadedFile::fake()->image('avatar.jpg')->size(3000);

        $this->json('POST', $uri, ['file' => $file])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testAuthenticatedUserCanUploadAvatar(): void
    {
        Storage::fake('s3');

        config(['app.cdn_url' => 'https://cdn.example.com']);

        $uri = route('api.v1.users.avatar.store');

        /** @var User $user */
        $user = $this->fakeLogin();

        $file = UploadedFile::fake()->image('my-photo.jpg', 200, 200)->size(500);

        $response = $this->json('POST', $uri, ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id', 'name', 'email', 'account', 'avatar',
            ]);

        $avatarUrl = $response->json('avatar');
        $this->assertStringStartsWith('https://cdn.example.com/avatars/', $avatarUrl);
        $this->assertStringEndsWith('.jpg', $avatarUrl);
    }

    public function testUploadSavesUserAvatarRecord(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');

        /** @var User $user */
        $user = $this->fakeLogin();

        $file = UploadedFile::fake()->image('my-photo.jpg', 200, 200)->size(500);

        $this->json('POST', $uri, ['file' => $file])
            ->assertStatus(200);

        $this->assertDatabaseCount('user_avatars', 1);

        $avatar = UserAvatar::first();
        $this->assertEquals($user->id, $avatar->user_id);
        $this->assertEquals('my-photo.jpg', $avatar->filename);
        $this->assertStringStartsWith("avatars/{$user->id}/", $avatar->path);
        $this->assertStringEndsWith('.jpg', $avatar->path);
    }

    public function testReuploadingCreatesNewAvatarRecord(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');

        /** @var User $user */
        $user = $this->fakeLogin();

        $file1 = UploadedFile::fake()->image('first.jpg', 100, 100)->size(200);
        $this->json('POST', $uri, ['file' => $file1])->assertStatus(200);

        $firstPath = $user->fresh()->avatar;

        $file2 = UploadedFile::fake()->image('second.png', 150, 150)->size(300);
        $this->json('POST', $uri, ['file' => $file2])->assertStatus(200);

        $secondPath = $user->fresh()->avatar;

        $this->assertDatabaseCount('user_avatars', 2);
        $this->assertNotEquals($firstPath, $secondPath);
        $this->assertStringEndsWith('.png', $secondPath);

        $this->assertDatabaseHas('user_avatars', ['path' => $firstPath]);
        $this->assertDatabaseHas('user_avatars', ['path' => $secondPath]);
    }
}
