<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Oauth;

use Mockery;
use Tests\TestCase;
use App\Models\User;
use App\Models\Oauth;
use RuntimeException;
use Hypervel\Support\Facades\Config;
use Hypervel\Socialite\Facades\Socialite;
use Hypervel\Socialite\Two\GoogleProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Socialite\Two\User as SocialiteUser;

/**
 * @internal
 * @coversNothing
 */
class CallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://rsspilot.app/oauth/google/callback';

    private const CODE = '4/0AfJohXlFakeAuthorizationCode';

    private const PROVIDER_ID = '112345678901234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.google.client_id', 'test-google-client-id');
        Config::set('services.google.client_secret', 'test-google-client-secret');
        Config::set('services.google.redirect', '');
    }

    private function uri(string $provider = Oauth::PROVIDER_GOOGLE): string
    {
        return route('api.v1.oauth.callback.store', ['provider' => $provider]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['code' => self::CODE, 'redirect' => self::REDIRECT], $overrides);
    }

    /**
     * provider 那一段一律 mock——測試不對外發請求。
     */
    private function fakeProviderUser(array $overrides = []): SocialiteUser
    {
        $socialUser = new SocialiteUser();
        $socialUser->map(array_merge([
            'id'     => self::PROVIDER_ID,
            'name'   => 'Ada Lovelace',
            'email'  => 'ada@example.com',
            'avatar' => 'https://lh3.googleusercontent.com/a/photo.jpg',
        ], $overrides));
        $socialUser->setRaw(['sub' => self::PROVIDER_ID]);
        $socialUser->setToken('google-access-token');
        $socialUser->setRefreshToken('google-refresh-token');
        $socialUser->setExpiresIn(3599);

        return $socialUser;
    }

    private function mockProvider(SocialiteUser $socialUser, string $expectedRedirect = self::REDIRECT): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->with($expectedRedirect)->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')
            ->with(Oauth::PROVIDER_GOOGLE)
            ->andReturn($provider);
    }

    public function testStoreIssuesAccessTokenForANewUser(): void
    {
        $this->mockProvider($this->fakeProviderUser());

        $response = $this->json('POST', $this->uri(), $this->payload());

        $response->assertStatus(201);

        $this->assertIsString($response->json('access_token'));
        $this->assertSame('bearer', $response->json('token_type'));

        $user = User::query()
            ->where('social_type', Oauth::PROVIDER_GOOGLE)
            ->where('provider_id', self::PROVIDER_ID)
            ->first();

        $this->assertNotNull($user);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('Ada Lovelace', $user->name);
    }

    public function testStoreStoresProviderCredentials(): void
    {
        $this->mockProvider($this->fakeProviderUser());

        $this->json('POST', $this->uri(), $this->payload())->assertStatus(201);

        $oauth = Oauth::query()
            ->where('provider', Oauth::PROVIDER_GOOGLE)
            ->where('provider_id', self::PROVIDER_ID)
            ->first();

        $this->assertNotNull($oauth);
        $this->assertSame('google-access-token', $oauth->token);
        $this->assertSame('google-refresh-token', $oauth->refresh_token);
        $this->assertSame(3599, $oauth->expires_in);
        $this->assertSame(['sub' => self::PROVIDER_ID], $oauth->data);
    }

    public function testStoreReusesTheExistingUserOnSecondSignIn(): void
    {
        $existing = User::factory()->create([
            'social_type' => Oauth::PROVIDER_GOOGLE,
            'provider_id' => self::PROVIDER_ID,
        ]);

        $this->mockProvider($this->fakeProviderUser());

        $this->json('POST', $this->uri(), $this->payload())->assertStatus(201);

        // 同一個 provider_id 不能長出第二個帳號，否則使用者的資料會分家。
        $this->assertSame(
            1,
            User::query()->where('provider_id', self::PROVIDER_ID)->count()
        );
        $this->assertSame(
            1,
            Oauth::query()->where('provider_id', self::PROVIDER_ID)->count()
        );
        $this->assertNotNull($existing->fresh());
    }

    public function testStoreDoesNotRequireAuthentication(): void
    {
        // 登入流程的收尾，此時使用者當然還沒有我們的 token。
        $this->mockProvider($this->fakeProviderUser());

        $this->json('POST', $this->uri(), $this->payload())->assertStatus(201);
    }

    public function testStoreTruncatesOverlongProviderFields(): void
    {
        $this->mockProvider($this->fakeProviderUser([
            'name'   => str_repeat('a', User::NAME_MAX_LENGTH + 50),
            'avatar' => 'https://lh3.googleusercontent.com/' . str_repeat('b', 300),
        ]));

        $this->json('POST', $this->uri(), $this->payload())->assertStatus(201);

        $user = User::query()->where('provider_id', self::PROVIDER_ID)->first();

        // sqlite 不會因為超長而失敗，MySQL 會——截斷是寫入前就得做完的事。
        $this->assertSame(User::NAME_MAX_LENGTH, mb_strlen($user->name));
        $this->assertSame(User::AVATAR_MAX_LENGTH, mb_strlen($user->avatar));
    }

    public function testStoreRejectsMissingCode(): void
    {
        $this->json('POST', $this->uri(), ['redirect' => self::REDIRECT])->assertStatus(422);
    }

    public function testStoreRejectsMissingRedirect(): void
    {
        $this->json('POST', $this->uri(), ['code' => self::CODE])->assertStatus(422);
    }

    public function testStoreRejectsMalformedRedirect(): void
    {
        $this->json('POST', $this->uri(), $this->payload(['redirect' => 'not-a-url']))
            ->assertStatus(422);
    }

    public function testStoreRejectsUnknownProvider(): void
    {
        $this->json('POST', $this->uri('line'), $this->payload())->assertStatus(422);
    }

    public function testStoreRejectsKnownProviderWithoutCredentials(): void
    {
        Config::set('services.facebook.client_id', null);

        $this->json('POST', $this->uri(Oauth::PROVIDER_FACEBOOK), $this->payload())
            ->assertStatus(422);
    }

    public function testStoreRejectsACodeTheProviderRefuses(): void
    {
        // 過期或被用過的 code 會讓 Socialite 從 provider 收到 4xx 並拋例外。
        // 那是呼叫端的錯，必須回 422 而不是 500，也不能把 provider 的原文吐出去。
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new RuntimeException('invalid_grant'));

        Socialite::shouldReceive('driver')
            ->with(Oauth::PROVIDER_GOOGLE)
            ->andReturn($provider);

        $response = $this->json('POST', $this->uri(), $this->payload());

        $response->assertStatus(422);
        $this->assertStringNotContainsString('invalid_grant', $response->getContent());
    }
}
