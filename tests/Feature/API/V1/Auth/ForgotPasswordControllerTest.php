<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Auth;

use Carbon\Carbon;
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
            'email' => 'validuser@example.com',
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

    public function testUpdateSuccessWithLinkFromMail(): void
    {
        $user = User::factory()->create([
            'email'    => 'validuser5@example.com',
            'password' => Hash::make('oldpassword'),
        ]);

        $this->json('PUT', route('api.v1.auth.forgot-password.update'), $this->resetLinkParams($user) + [
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
            ->assertJsonPath('token_type', 'bearer');

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    /**
     * 舊版的 token 是 Hash::make(id)：任何知道使用者 id 的人都能自己算出來，
     * 不必收信就能改掉別人的密碼。這種自製的 token 不能被接受。
     */
    public function testUpdateRejectsTokenForgedFromUserId(): void
    {
        $user = User::factory()->create([
            'email'    => 'victim@example.com',
            'password' => Hash::make('oldpassword'),
        ]);

        $params = $this->resetLinkParams($user);
        $params['token'] = Hash::make(strval($user->id));

        $this->assertResetLinkRejected($user, $params);
    }

    public function testUpdateRejectsTamperedSignature(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword')]);

        $params = $this->resetLinkParams($user);
        $params['signature'] = str_repeat('0', 64);

        $this->assertResetLinkRejected($user, $params);
    }

    /** 簽章涵蓋 expires：自行把期限往後改，簽章就對不上。 */
    public function testUpdateRejectsExtendedExpiry(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword')]);

        $params = $this->resetLinkParams($user);
        $params['expires'] = (int) $params['expires'] + 3600;

        $this->assertResetLinkRejected($user, $params);
    }

    public function testUpdateRejectsExpiredLink(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword')]);

        $params = $this->resetLinkParams($user);
        Carbon::setTestNow(now()->addMinutes(61));

        try {
            $this->assertResetLinkRejected($user, $params);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 信裡的連結只能用一次：密碼改過之後，同一條連結就失效。 */
    public function testUpdateRejectsReusedLink(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword')]);
        $params = $this->resetLinkParams($user);

        $this->json('PUT', route('api.v1.auth.forgot-password.update'), $params + [
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        $this->json('PUT', route('api.v1.auth.forgot-password.update'), $params + [
            'password'              => 'another456789',
            'password_confirmation' => 'another456789',
        ])
            ->assertStatus(422)
            ->assertJsonPath('messages.token', [__('validators.controllers.auth.reset_link_invalid')]);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    /** 換成別人的 id：簽章與 token 都綁在原本的使用者上。 */
    public function testUpdateRejectsLinkForAnotherUser(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('ownerpass')]);
        $victim = User::factory()->create(['password' => Hash::make('victimpass')]);

        $params = $this->resetLinkParams($owner);
        $params['id'] = strval($victim->id);

        $this->assertResetLinkRejected($victim, $params, 'victimpass');
    }

    public function testUpdateValidationPasswordTooShort(): void
    {
        $user = User::factory()->create(['email' => 'validuser3@example.com']);

        $this->json('PUT', route('api.v1.auth.forgot-password.update'), $this->resetLinkParams($user) + [
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function testUpdateValidationPasswordMismatch(): void
    {
        $user = User::factory()->create(['email' => 'validuser4@example.com']);

        $this->json('PUT', route('api.v1.auth.forgot-password.update'), $this->resetLinkParams($user) + [
            'password'              => 'newpassword123',
            'password_confirmation' => 'different456',
        ])->assertStatus(422);
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

    /**
     * 走真正的 store 流程拿到寄出的重設連結，回傳連結上的 query 參數
     * （expires / id / token / signature），也就是前端會原樣送回 PUT 的那一組。
     *
     * @return array<string, string>
     */
    private function resetLinkParams(User $user): array
    {
        Mail::fake();

        $this->json('POST', route('api.v1.auth.forgot-password.store'), ['email' => $user->email])
            ->assertStatus(200);

        $link = null;
        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$link) {
            $link = $mail->token;

            return true;
        });

        parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $params);

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertResetLinkRejected(User $user, array $params, string $originalPassword = 'oldpassword'): void
    {
        $this->json('PUT', route('api.v1.auth.forgot-password.update'), $params + [
            'password'              => 'hijacked12345',
            'password_confirmation' => 'hijacked12345',
        ])
            ->assertStatus(422)
            ->assertJsonPath('messages.token', [__('validators.controllers.auth.reset_link_invalid')]);

        $this->assertTrue(Hash::check($originalPassword, $user->fresh()->password));
    }
}
