<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Auth;

use Tests\TestCase;
use App\Models\User;
use App\Mail\ResetPasswordMail;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Mail;
use App\Validators\ForgotPasswordValidator;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class ForgotPasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // POST /auth/forgot-password  (store)
    // -------------------------------------------------------------------------

    public function testStoreValidationMissingAccount(): void
    {
        $uri = route('api.v1.auth.forgot-password.store');

        $messages = (new ForgotPasswordValidator([]))->getMessages();

        $this->json('POST', $uri)
            ->assertStatus(422)
            ->assertJsonPath('messages.email', [$messages['email.required']]);
    }

    public function testStoreValidationAccountTooShort(): void
    {
        $uri = route('api.v1.auth.forgot-password.store');

        $messages = (new ForgotPasswordValidator([]))->getMessages();

        $this->json('POST', $uri, ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('messages.email', [$messages['email.email']]);
    }

    public function testStoreValidationAccountTooLong(): void
    {
        $uri = route('api.v1.auth.forgot-password.store');

        $messages = (new ForgotPasswordValidator([]))->getMessages();

        $this->json('POST', $uri, ['email' => str_repeat('a', 250) . '@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('messages.email', [$messages['email.max']]);
    }

    public function testStoreWithNonExistentAccount(): void
    {
        Mail::fake();

        $uri = route('api.v1.auth.forgot-password.store');

        $this->json('POST', $uri, ['email' => 'nonexistent@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('messages.email', [__('validators.controllers.auth.invalid_credentials')]);

        Mail::assertNothingSent();
    }

    public function testStoreSuccess(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'validuser@example.com',
            'email'   => 'validuser@example.com',
        ]);

        $uri = route('api.v1.auth.forgot-password.store');

        $this->json('POST', $uri, ['email' => 'validuser@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('message', __('passwords.sent'));

        Mail::assertSent(ResetPasswordMail::class);
    }

    // -------------------------------------------------------------------------
    // PUT /auth/forgot-password  (update)
    // -------------------------------------------------------------------------

    public function testUpdateValidationMissingFields(): void
    {
        $uri = route('api.v1.auth.forgot-password.update');

        $messages = (new ForgotPasswordValidator([]))->getMessages();

        $this->json('PUT', $uri)
            ->assertStatus(422)
            ->assertJsonPath('messages.expires', [$messages['expires.required']])
            ->assertJsonPath('messages.token', [$messages['token.required']])
            ->assertJsonPath('messages.signature', [$messages['signature.required']]);
    }

    public function testUpdateWithInvalidToken(): void
    {
        $user = User::factory()->create(['email' => 'validuser2@example.com']);

        $uri = route('api.v1.auth.forgot-password.update');

        $this->json('PUT', $uri, [
            'expires'               => now()->addHour()->timestamp,
            'id'                    => $user->id,
            'token'                 => 'invalid-token',
            'signature'             => 'any-signature',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('messages.token', 'Invalid token.');
    }

    public function testUpdateValidationPasswordTooShort(): void
    {
        $user = User::factory()->create(['email' => 'validuser3@example.com']);
        $token = Hash::make(strval($user->id));

        $uri = route('api.v1.auth.forgot-password.update');

        $this->json('PUT', $uri, [
            'expires'               => now()->addHour()->timestamp,
            'id'                    => $user->id,
            'token'                 => $token,
            'signature'             => 'any-signature',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function testUpdateValidationPasswordMismatch(): void
    {
        $user = User::factory()->create(['email' => 'validuser4@example.com']);
        $token = Hash::make(strval($user->id));

        $uri = route('api.v1.auth.forgot-password.update');

        $this->json('PUT', $uri, [
            'expires'               => now()->addHour()->timestamp,
            'id'                    => $user->id,
            'token'                 => $token,
            'signature'             => 'any-signature',
            'password'              => 'newpassword123',
            'password_confirmation' => 'different456',
        ])->assertStatus(422);
    }

    public function testUpdateSuccess(): void
    {
        $user = User::factory()->create([
            'email'    => 'validuser5@example.com',
            'password' => Hash::make('oldpassword'),
        ]);
        $token = Hash::make(strval($user->id));

        $uri = route('api.v1.auth.forgot-password.update');

        $this->json('PUT', $uri, [
            'expires'               => now()->addHour()->timestamp,
            'id'                    => $user->id,
            'token'                 => $token,
            'signature'             => 'any-signature',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
            ->assertJsonPath('token_type', 'bearer');

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function testResetPasswordMailRendersWithRSSPilotBranding(): void
    {
        $tokenUrl = 'http://api.example.com/v1/auth/forgot-password?token=abc&id=1&expires=9999999999&signature=xyz';
        $mailable = new ResetPasswordMail($tokenUrl, 60);
        $html = $mailable->render();

        $this->assertStringContainsString('RSSPilot', $html);
        $this->assertStringContainsString('60', $html);
        $this->assertStringContainsString('If you did not request a password reset', $html);
        $this->assertStringContainsString('/reset-password?token=abc', $html);
    }
}
