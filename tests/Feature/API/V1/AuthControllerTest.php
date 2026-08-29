<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Validators\AuthValidator;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function testStoreValidation()
    {
        $uri = route('api.v1.auth.store');

        $this->json('POST', $uri)->assertStatus(422)->assertJsonStructure([
            'messages' => [
                'account',
                'password',
            ],
        ]);

        $messages = (new AuthValidator([]))->getMessages();

        $params = [
            'account' => 'abc',
        ];
        $this->json('POST', $uri, $params)->assertStatus(422)->assertJsonPath(
            'messages.account',
            [$messages['account.min']]
        );

        $params['account'] = fake()->userName();
        $this->json('POST', $uri, $params)->assertStatus(422)->assertJsonPath(
            'messages.password',
            [$messages['password.required']]
        );
    }

    public function testStoreWithInvalidCredentials()
    {
        $uri = route('api.v1.auth.store');
        User::factory()->create([
            'account'  => 'testuser',
            'password' => bcrypt('password123'),
        ]);

        $params = [
            'account'  => 'testuser',
            'password' => 'wrongpassword',
        ];

        $this->json('POST', $uri, $params)
            ->assertStatus(422)
            ->assertJsonPath('messages.password', [__('validators.controllers.auth.invalid_credentials')]);
    }

    public function testStoreSuccess()
    {
        $uri = route('api.v1.auth.store');
        User::factory()->create([
            'account'  => 'testuser',
            'password' => bcrypt('password123'),
        ]);

        $params = [
            'account'  => 'testuser',
            'password' => 'password123',
        ];

        $this->json('POST', $uri, $params)
            ->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ])
            ->assertJsonPath('token_type', 'bearer');
    }

    public function testRegisterValidation()
    {
        $messages = (new AuthValidator([]))->getMessages();
        $uri = route('api.v1.auth.register');

        $this->json('POST', $uri)->assertStatus(422)->assertJsonStructure([
            'messages' => [
                'account',
                'email',
                'password',
            ],
        ])->assertJsonPath('messages.account', [$messages['account.required']])
            ->assertJsonPath('messages.email', [$messages['email.required']])
            ->assertJsonPath('messages.password', [$messages['password.required']]);

        $params = [
            'account'  => fake()->userName(),
            'email'    => fake()->email(),
            'password' => 'Password@123',
        ];
        $this->json('POST', $uri, $params)->assertStatus(422)
            ->assertJsonPath('messages.password', [$messages['password.confirmed']]);

        $params = [
            'account'               => fake()->userName(),
            'email'                 => fake()->email(),
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ];
        $this->json('POST', $uri, $params)->assertStatus(422)
            ->assertJsonPath('messages.password', [$messages['password.regex']]);

        $longPassword = 'P@ssw0rd' . str_repeat('a', 57);
        $params = [
            'account'               => fake()->userName(),
            'email'                 => fake()->email(),
            'password'              => $longPassword,
            'password_confirmation' => $longPassword,
        ];
        $this->json('POST', $uri, $params)->assertStatus(422)
            ->assertJsonPath('messages.password', [$messages['password.max']]);
    }

    public function testRegisterSuccess()
    {
        $uri = route('api.v1.auth.register');
        $params = [
            'account'               => 'newuser',
            'email'                 => 'newuser@example.com',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
        ];

        $this->json('POST', $uri, $params)
            ->assertStatus(201)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ]);

        $this->assertDatabaseHas('users', [
            'account' => 'newuser',
            'email'   => 'newuser@example.com',
        ]);
    }

    public function testRegisterWithExistingAccount()
    {
        User::factory()->create(['account' => 'existinguser']);
        $uri = route('api.v1.auth.register');
        $params = [
            'account'               => 'existinguser',
            'email'                 => 'newemail@example.com',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
        ];

        $this->json('POST', $uri, $params)
            ->assertStatus(422)
            ->assertJsonPath('messages.account', [__('validators.auth.account.unique')]);
    }

    public function testLogoutWithoutToken()
    {
        $uri = route('api.v1.auth.logout');
        $this->json('POST', $uri)->assertStatus(401);
    }

    public function testLogoutWithToken()
    {
        $user = User::factory()->create();
        $token = auth('jwt')->login($user);

        $uri = route('api.v1.auth.logout');
        $this->withToken($token)->json('POST', $uri)
            ->assertStatus(200)
            ->assertContent('OK.');
    }

    public function testRefreshWithoutToken()
    {
        $uri = route('api.v1.auth.refresh');
        $this->json('POST', $uri)->assertStatus(401);
    }

    public function testRefreshWithToken()
    {
        $user = User::factory()->create();
        $token = auth('jwt')->login($user);

        $uri = route('api.v1.auth.refresh');
        $response = $this->withToken($token)->json('POST', $uri);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ])
            ->assertJsonPath('token_type', 'bearer');

        $this->assertNotEquals($token, $response->json('access_token'));
    }
}
