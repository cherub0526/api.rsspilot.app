<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Oauth;

use Tests\TestCase;
use App\Models\Oauth;
use Hypervel\Support\Facades\Config;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class RedirectControllerTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://rsspilot.app/oauth/callback';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.google.client_id', 'test-google-client-id');
        Config::set('services.google.client_secret', 'test-google-client-secret');
        Config::set('services.google.redirect', '');
    }

    private function uri(string $provider = Oauth::PROVIDER_GOOGLE): string
    {
        return route('api.v1.oauth.redirect.store', ['provider' => $provider]);
    }

    public function testStoreReturnsGoogleAuthorizationUrl(): void
    {
        $response = $this->json('POST', $this->uri(), ['redirect' => self::REDIRECT]);

        $response->assertStatus(200);

        $url = $response->json('url');

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // redirect_uri 必須是請求帶進來的那一個，而不是 config 的值——這正是這支
        // 端點存在的理由，所以是最該被鎖住的斷言。
        $this->assertSame(self::REDIRECT, $query['redirect_uri']);
        $this->assertSame('test-google-client-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile email', $query['scope']);

        // stateless()：帶 state 的模式需要 session，這支端點沒有。
        $this->assertArrayNotHasKey('state', $query);
    }

    public function testStoreDoesNotRequireAuthentication(): void
    {
        // 這是登入流程的起點，未登入時就必須能用。
        $this->json('POST', $this->uri(), ['redirect' => self::REDIRECT])
            ->assertStatus(200);
    }

    public function testStoreRejectsMissingRedirect(): void
    {
        $this->json('POST', $this->uri(), [])->assertStatus(422);
    }

    public function testStoreRejectsMalformedRedirect(): void
    {
        $this->json('POST', $this->uri(), ['redirect' => 'not-a-url'])->assertStatus(422);
    }

    public function testStoreRejectsRedirectLongerThanTheLimit(): void
    {
        $tooLong = 'https://rsspilot.app/?q=' . str_repeat('a', 256);

        $this->json('POST', $this->uri(), ['redirect' => $tooLong])->assertStatus(422);
    }

    public function testStoreRejectsUnknownProvider(): void
    {
        $this->json('POST', $this->uri('line'), ['redirect' => self::REDIRECT])
            ->assertStatus(422);
    }

    public function testStoreRejectsKnownProviderWithoutCredentials(): void
    {
        // facebook 在 Oauth::$providerMaps 裡，所以過得了驗證；但沒有 client_id
        // 就該回 400，而不是讓 SocialiteManager 建 driver 時炸成 500。
        Config::set('services.facebook.client_id', null);

        $this->json('POST', $this->uri(Oauth::PROVIDER_FACEBOOK), ['redirect' => self::REDIRECT])
            ->assertStatus(422);
    }

    public function testStoreSupportsFacebookOnceCredentialsAreSet(): void
    {
        // 「未來支援其他 provider 只需要設定環境變數」這件事要有測試守著，
        // 否則下次有人改動 provider 判斷時不會知道自己弄壞了什麼。
        Config::set('services.facebook.client_id', 'test-facebook-client-id');
        Config::set('services.facebook.client_secret', 'test-facebook-client-secret');
        Config::set('services.facebook.redirect', '');

        $response = $this->json('POST', $this->uri(Oauth::PROVIDER_FACEBOOK), [
            'redirect' => self::REDIRECT,
        ]);

        $response->assertStatus(200);

        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);

        $this->assertSame(self::REDIRECT, $query['redirect_uri']);
        $this->assertSame('test-facebook-client-id', $query['client_id']);
    }
}
