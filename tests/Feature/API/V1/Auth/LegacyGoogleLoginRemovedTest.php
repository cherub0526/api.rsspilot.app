<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Auth;

use Mockery;
use Tests\TestCase;
use App\Models\User;
use App\Models\Oauth;
use Hypervel\Support\Facades\Config;
use Hypervel\Socialite\Facades\Socialite;
use Hypervel\Socialite\Two\GoogleProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Socialite\Two\User as SocialiteUser;
use Hypervel\Foundation\Testing\Http\TestResponse;

/**
 * security-hotfix-1 驗收（C1）：舊的 `POST /v1/auth/google` 不向 Google 驗證就發 token，要整條移除；
 * Google 登入只剩 OAuth redirect／callback。
 *
 * 被移除的端點路徑寫死字串（testing.md 規定用 route()，但路由名稱會跟著端點消失，route() 會直接拋例外而不是 404）；
 * 留下的 OAuth 端點照規定用 route()。
 * 送出的內容能通過舊端點的驗證，所以修正前的失敗原因是「拿到 token」，不是 422。
 *
 * @internal
 * @coversNothing
 */
class LegacyGoogleLoginRemovedTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_PATH = '/v1/auth/google';

    private function legacyLogin(string $email, string $providerId): TestResponse
    {
        return $this->json('POST', self::LEGACY_PATH, [
            'access_token' => 'x',
            'provider_id'  => $providerId,
            'email'        => $email,
            'name'         => 'Mallory',
            'avatar_url'   => 'https://example.test/a.jpg',
        ]);
    }

    private function assertNoToken(TestResponse $response): void
    {
        $this->assertStringNotContainsString('access_token', (string) $response->getContent());
    }

    // ── S01 ───────────────────────────────────────────

    public function testS01TheLegacyGoogleLoginNoLongerExists(): void
    {
        $victim = User::factory()->create([
            'social_type' => User::SOCIAL_TYPE_GOOGLE,
            'provider_id' => 'g-123',
        ]);
        $usersBefore = User::query()->count();

        $response = $this->legacyLogin($victim->email, 'g-123');

        $response->assertStatus(404);
        $this->assertNoToken($response);
        $this->assertSame($usersBefore, User::query()->count(), 'no user is created');
        $this->assertSame('g-123', $victim->refresh()->provider_id);
        $this->assertSame(User::SOCIAL_TYPE_GOOGLE, $victim->social_type);
    }

    // ── S02 ───────────────────────────────────────────

    public function testS02TheLegacyEndpointCannotPreRegisterAGoogleAccount(): void
    {
        $response = $this->legacyLogin('not-yet@example.test', 'attacker-chosen-id');

        $response->assertStatus(404);
        $this->assertNoToken($response);
        $this->assertFalse(User::query()->where('email', 'not-yet@example.test')->exists());
        $this->assertFalse(User::query()->where('provider_id', 'attacker-chosen-id')->exists());
    }

    // ── S03 ───────────────────────────────────────────

    public function testS03GoogleLoginThroughOauthRedirectAndCallbackStillWorks(): void
    {
        Config::set('services.google.client_id', 'test-google-client-id');
        Config::set('services.google.client_secret', 'test-google-client-secret');
        Config::set('services.google.redirect', '');
        $redirect = 'https://rsspilot.app/oauth/google/callback';

        $this->json('POST', route('api.v1.oauth.redirect.store', ['provider' => Oauth::PROVIDER_GOOGLE]), ['redirect' => $redirect])
            ->assertStatus(200)
            ->assertJsonPath('url', fn ($url) => is_string($url) && str_starts_with($url, 'https://accounts.google.com/'));

        $socialUser = new SocialiteUser();
        $socialUser->map([
            'id'     => '112345678901234567890',
            'name'   => 'Ada Lovelace',
            'email'  => 'ada@example.com',
            'avatar' => 'https://lh3.googleusercontent.com/a/photo.jpg',
        ]);
        $socialUser->setRaw(['sub' => '112345678901234567890']);
        $socialUser->setToken('google-access-token');
        $socialUser->setRefreshToken('google-refresh-token');
        $socialUser->setExpiresIn(3599);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->with($redirect)->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with(Oauth::PROVIDER_GOOGLE)->andReturn($provider);

        $response = $this->json('POST', route('api.v1.oauth.callback.store', ['provider' => Oauth::PROVIDER_GOOGLE]), ['code' => '4/0AfJohXlFakeCode', 'redirect' => $redirect]);

        $response->assertStatus(201);
        $this->assertIsString($response->json('access_token'));
        $this->assertSame('bearer', $response->json('token_type'));
        $this->assertTrue(User::query()
            ->where('social_type', User::SOCIAL_TYPE_GOOGLE)
            ->where('provider_id', '112345678901234567890')
            ->exists());
    }
}
