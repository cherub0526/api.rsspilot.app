<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Mail\VerifyEmailMail;
use Hypervel\Support\Facades\Mail;
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
                'email',
                'password',
            ],
        ]);

        $messages = (new AuthValidator([]))->getMessages();

        $params = [
            'email' => 'not-an-email',
        ];
        $this->json('POST', $uri, $params)->assertStatus(422)->assertJsonPath(
            'messages.email',
            [$messages['email.email']]
        );

        $params['email'] = fake()->safeEmail();
        $this->json('POST', $uri, $params)->assertStatus(422)->assertJsonPath(
            'messages.password',
            [$messages['password.required']]
        );
    }

    public function testStoreWithInvalidCredentials()
    {
        $uri = route('api.v1.auth.store');
        User::factory()->create([
            'email'    => 'testuser@example.com',
            'password' => bcrypt('password123'),
        ]);

        $params = [
            'email'    => 'testuser@example.com',
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
            'email'    => 'testuser@example.com',
            'password' => bcrypt('password123'),
        ]);

        $params = [
            'email'    => 'testuser@example.com',
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
        $uri = route('api.v1.auth.register.store');

        $this->json('POST', $uri)->assertStatus(422)->assertJsonStructure([
            'messages' => [
                'email',
                'password',
            ],
        ])->assertJsonPath('messages.email', [$messages['email.required']])
            ->assertJsonPath('messages.password', [$messages['password.required']]);

        $params = [
            'email'    => fake()->safeEmail(),
            'password' => 'Password@123',
        ];
        $this->json('POST', $uri, $params)->assertStatus(422)
            ->assertJsonPath('messages.password', [$messages['password.confirmed']]);

        $params = [
            'email'                 => fake()->safeEmail(),
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ];
        $this->json('POST', $uri, $params)->assertStatus(422)
            ->assertJsonPath('messages.password', [$messages['password.regex']]);

        $longPassword = 'P@ssw0rd' . str_repeat('a', 57);
        $params = [
            'email'                 => fake()->safeEmail(),
            'password'              => $longPassword,
            'password_confirmation' => $longPassword,
        ];
        $this->json('POST', $uri, $params)->assertStatus(422)
            ->assertJsonPath('messages.password', [$messages['password.max']]);
    }

    public function testRegisterSuccess()
    {
        Mail::fake();

        $uri = route('api.v1.auth.register.store');
        $params = [
            'email'                 => 'newuser@example.com',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
        ];

        // 202 而不是 201，而且刻意不含 access_token——註冊還沒完成
        $this->json('POST', $uri, $params)
            ->assertStatus(202)
            ->assertJsonMissing(['access_token']);

        $this->assertDatabaseHas('users', [
            'email'             => 'newuser@example.com',
            'email_verified_at' => null,
        ]);

        $user = User::query()->where('email', 'newuser@example.com')->firstOrFail();
        $this->assertDatabaseHas('email_verification_codes', ['user_id' => $user->id]);

        Mail::assertSent(VerifyEmailMail::class);
    }

    public function testRegisterWithExistingEmail()
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $uri = route('api.v1.auth.register.store');
        $params = [
            'email'                 => 'existing@example.com',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
        ];

        $this->json('POST', $uri, $params)
            ->assertStatus(422)
            ->assertJsonPath('messages.email', [__('validators.auth.email.unique')]);
    }

    /** 註冊到一半跑掉的人再回來登入：不發 token，改回導驗證流程。 */
    public function testStoreWithUnverifiedEmailReturnsCode()
    {
        User::factory()->unverified()->create([
            'email'    => 'pending@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->json('POST', route('api.v1.auth.store'), [
            'email'    => 'pending@example.com',
            'password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'email_unverified');
    }

    /** Google 建立、從未設過密碼的帳號：給專屬代碼而不是「密碼錯誤」。 */
    public function testStoreWithGoogleOnlyAccountReturnsPasswordNotSet()
    {
        User::factory()->create([
            'email'       => 'googleonly@example.com',
            'social_type' => User::SOCIAL_TYPE_GOOGLE,
            'provider_id' => '1234567890',
        ]);

        $this->json('POST', route('api.v1.auth.store'), [
            'email'    => 'googleonly@example.com',
            'password' => 'whatever123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'password_not_set');
    }

    public function testLogoutWithoutToken()
    {
        $uri = route('api.v1.auth.logout.store');
        $this->json('POST', $uri)->assertStatus(401);
    }

    public function testLogoutWithToken()
    {
        $user = User::factory()->create();
        $token = auth('jwt')->login($user);

        $uri = route('api.v1.auth.logout.store');
        $this->withToken($token)->json('POST', $uri)
            ->assertStatus(200)
            ->assertContent('OK.');
    }

    public function testRefreshWithoutToken()
    {
        $uri = route('api.v1.auth.refresh.store');
        $this->json('POST', $uri)->assertStatus(401);
    }

    public function testRefreshWithToken()
    {
        $user = User::factory()->create();
        $token = auth('jwt')->login($user);

        $uri = route('api.v1.auth.refresh.store');
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
