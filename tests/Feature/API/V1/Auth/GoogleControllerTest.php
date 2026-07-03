<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Auth;

use Tests\TestCase;
use App\Models\User;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class GoogleControllerTest extends TestCase
{
    use RefreshDatabase;

    private array $validParams = [
        'access_token' => 'ya29.fake-access-token',
        'avatar_url'   => 'https://lh3.googleusercontent.com/a/photo.jpg',
        'email'        => 'googleuser@example.com',
        'name'         => 'Google User',
        'provider_id'  => 'google-provider-id-123',
    ];

    public function testStoreValidatesRequiredFields(): void
    {
        $this->json('POST', route('api.v1.auth.google.store'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['access_token', 'avatar_url', 'email', 'name']]);
    }

    public function testStoreValidatesEmailFormat(): void
    {
        $params = array_merge($this->validParams, ['email' => 'not-an-email']);

        $this->json('POST', route('api.v1.auth.google.store'), $params)
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['email']]);
    }

    public function testStoreCreatesNewUserAndReturnsToken(): void
    {
        $response = $this->json('POST', route('api.v1.auth.google.store'), $this->validParams)
            ->assertStatus(201)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $this->assertEquals('bearer', $response->json('token_type'));

        $this->assertDatabaseHas('users', [
            'email'       => 'googleuser@example.com',
            'social_type' => User::SOCIAL_TYPE_GOOGLE,
            'provider_id' => 'google-provider-id-123',
        ]);
    }

    public function testStoreReturnsExistingUserOnRepeatLogin(): void
    {
        $this->json('POST', route('api.v1.auth.google.store'), $this->validParams)->assertStatus(201);

        $this->assertEquals(1, User::where('provider_id', 'google-provider-id-123')->count());

        // Logging in again with the same provider_id must not create a duplicate user.
        $this->json('POST', route('api.v1.auth.google.store'), $this->validParams)->assertStatus(201);

        $this->assertEquals(1, User::where('provider_id', 'google-provider-id-123')->count());
    }
}
