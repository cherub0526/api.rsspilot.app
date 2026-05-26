<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use Tests\TestCase;
use App\Models\User;
use App\Http\Resources\UserResource;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class UserResourceAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function testAvatarReturnsCdnUrlWhenSet(): void
    {
        config(['app.cdn_url' => 'https://cdn.example.com']);

        /** @var User $user */
        $user = User::factory()->make([
            'avatar' => 'avatars/01JXXXXX/550e8400-uuid.jpg',
        ]);

        $resource = new UserResource($user);
        $array = $resource->toArray();

        $this->assertArrayHasKey('avatar', $array);
        $this->assertEquals(
            'https://cdn.example.com/avatars/01JXXXXX/550e8400-uuid.jpg',
            $array['avatar']
        );
    }

    public function testAvatarReturnsNullWhenNotSet(): void
    {
        config(['app.cdn_url' => 'https://cdn.example.com']);

        /** @var User $user */
        $user = User::factory()->make(['avatar' => null]);

        $resource = new UserResource($user);
        $array = $resource->toArray();

        $this->assertArrayHasKey('avatar', $array);
        $this->assertNull($array['avatar']);
    }

    public function testAvatarCdnUrlTrimsTrailingSlash(): void
    {
        config(['app.cdn_url' => 'https://cdn.example.com/']);

        /** @var User $user */
        $user = User::factory()->make([
            'avatar' => 'avatars/01JXXXXX/uuid.jpg',
        ]);

        $resource = new UserResource($user);
        $array = $resource->toArray();

        $this->assertEquals(
            'https://cdn.example.com/avatars/01JXXXXX/uuid.jpg',
            $array['avatar']
        );
    }
}
