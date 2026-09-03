<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Setting;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class UsersControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testIndexRequiresAuth(): void
    {
        $this->json('GET', route('api.v1.users.index'))->assertStatus(401);
    }

    public function testIndexReturnsCurrentUserProfile(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $this->json('GET', route('api.v1.users.index'))
            ->assertStatus(200)
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('name', $user->name)
            ->assertJsonPath('email', $user->email)
            // account 已從輸出移除（email 取代它成為身分識別）
            ->assertJsonMissingPath('account');
    }

    public function testIndexIncludesSettingWhenPresent(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        Setting::create([
            'user_id' => $user->id,
            'data'    => ['ai' => ['language' => 'en']],
        ]);

        $this->json('GET', route('api.v1.users.index'))
            ->assertStatus(200)
            ->assertJsonPath('setting.ai.language', 'en');
    }

    public function testUpdateRequiresAuth(): void
    {
        $this->json('PUT', route('api.v1.users.update'), ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertStatus(401);
    }

    public function testUpdateValidatesRequiredFields(): void
    {
        $this->fakeLogin();

        $this->json('PUT', route('api.v1.users.update'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['name', 'email']]);
    }

    public function testUpdateValidatesEmailFormat(): void
    {
        $this->fakeLogin();

        $this->json('PUT', route('api.v1.users.update'), ['name' => 'John', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('messages.email.0', __('validators.user.email.email'));
    }

    public function testUpdateSucceeds(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $this->json('PUT', route('api.v1.users.update'), ['name' => 'Updated Name', 'email' => 'updated@example.com'])
            ->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id'    => $user->id,
            'name'  => 'Updated Name',
            'email' => 'updated@example.com',
        ]);
    }
}
