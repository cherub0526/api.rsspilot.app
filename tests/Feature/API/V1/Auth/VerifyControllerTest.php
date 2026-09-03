<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Auth;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use App\Mail\VerifyEmailMail;
use Hypervel\Support\Facades\Mail;
use App\Models\EmailVerificationCode;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class VerifyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function pendingUser(string $email = 'pending@example.com'): User
    {
        return User::factory()->unverified()->create(['email' => $email]);
    }

    private function codeFor(User $user, array $overrides = []): EmailVerificationCode
    {
        return EmailVerificationCode::create(array_merge([
            'user_id'    => $user->id,
            'code'       => '048213',
            'token'      => str_repeat('a', 64),
            'attempts'   => 0,
            'expires_at' => Carbon::now()->addMinutes(EmailVerificationCode::TTL_MINUTES),
        ], $overrides));
    }

    public function testStoreValidation()
    {
        $this->json('POST', route('api.v1.auth.verify.store'))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['email', 'code']]);

        $this->json('POST', route('api.v1.auth.verify.store'), [
            'email' => 'pending@example.com',
            'code'  => '12',
        ])
            ->assertStatus(422)
            ->assertJsonPath('messages.code', [__('validators.auth.code.digits')]);
    }

    public function testStoreWithCodeIssuesToken()
    {
        $user = $this->pendingUser();
        $this->codeFor($user);

        $this->json('POST', route('api.v1.auth.verify.store'), [
            'email' => 'pending@example.com',
            'code'  => '048213',
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function testStoreWithWrongCodeReportsAttemptsLeft()
    {
        $user = $this->pendingUser();
        $this->codeFor($user);

        $this->json('POST', route('api.v1.auth.verify.store'), [
            'email' => 'pending@example.com',
            'code'  => '999999',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_invalid')
            ->assertJsonPath('attempts_left', EmailVerificationCode::MAX_ATTEMPTS - 1);

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function testStoreWithExpiredCode()
    {
        $user = $this->pendingUser();
        $this->codeFor($user, ['expires_at' => Carbon::now()->subMinute()]);

        $this->json('POST', route('api.v1.auth.verify.store'), [
            'email' => 'pending@example.com',
            'code'  => '048213',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_expired');
    }

    public function testStoreWithExhaustedAttempts()
    {
        $user = $this->pendingUser();
        $this->codeFor($user, ['attempts' => EmailVerificationCode::MAX_ATTEMPTS]);

        $this->json('POST', route('api.v1.auth.verify.store'), [
            'email' => 'pending@example.com',
            'code'  => '048213',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_expired');
    }

    public function testStoreWithTokenIssuesToken()
    {
        $user = $this->pendingUser();
        $record = $this->codeFor($user);

        $this->json('POST', route('api.v1.auth.verify.store'), ['token' => $record->token])
            ->assertStatus(200)
            ->assertJsonStructure(['access_token']);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /** 碼與連結是同一組憑證：用掉連結之後，碼必須失效。 */
    public function testTokenAndCodeShareOneCredential()
    {
        $user = $this->pendingUser();
        $record = $this->codeFor($user);

        $this->json('POST', route('api.v1.auth.verify.store'), ['token' => $record->token])
            ->assertStatus(200);

        $this->json('POST', route('api.v1.auth.verify.store'), [
            'email' => 'pending@example.com',
            'code'  => '048213',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_expired');
    }

    public function testResendIssuesNewCodeAndInvalidatesOld()
    {
        Mail::fake();

        $user = $this->pendingUser();
        $old  = $this->codeFor($user, ['created_at' => Carbon::now()->subMinutes(5)]);

        $this->json('POST', route('api.v1.auth.verify.resend.store'), [
            'email' => 'pending@example.com',
        ])->assertStatus(202);

        Mail::assertSent(VerifyEmailMail::class);
        $this->assertNotNull($old->refresh()->consumed_at);
        $this->assertSame(2, EmailVerificationCode::query()->where('user_id', $user->id)->count());
    }

    /** 冷卻在後端擋，前端的倒數只是體驗。 */
    public function testResendIsThrottled()
    {
        Mail::fake();

        $user = $this->pendingUser();
        $this->codeFor($user);

        $this->json('POST', route('api.v1.auth.verify.resend.store'), [
            'email' => 'pending@example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'resend_throttled');
    }

    /** 這支端點不需登入即可打，不能拿它來探測 email 是否存在。 */
    public function testUnknownEmailDoesNotLeakExistence()
    {
        $this->json('POST', route('api.v1.auth.verify.store'), [
            'email' => 'nobody@example.com',
            'code'  => '048213',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_expired');
    }

    /** 已驗證過的帳號同樣不該被再驗一次。 */
    public function testAlreadyVerifiedEmailIsRejected()
    {
        User::factory()->create(['email' => 'done@example.com']);

        $this->json('POST', route('api.v1.auth.verify.store'), [
            'email' => 'done@example.com',
            'code'  => '048213',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_expired');
    }
}
