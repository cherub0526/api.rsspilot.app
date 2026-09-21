<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Auth;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class RefreshControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 直接拆 JWT 的 payload，而不是問後端的 JWT manager。
     *
     * 前端就是這樣讀 exp 的（base64url 解第二段），所以斷言要建立在同一份資料上；
     * 借用後端自己的 decoder 會讓「token 裡真的有沒有這個 claim」變成問錯對象。
     */
    private function payload(string $token): array
    {
        $segment = explode('.', $token)[1] ?? '';
        $json    = base64_decode(strtr($segment, '-_', '+/'), true);

        return json_decode((string) $json, true) ?: [];
    }

    public function testRefreshWithoutToken()
    {
        $uri = route('api.v1.auth.refresh.store');
        $this->json('POST', $uri)->assertStatus(401);
    }

    public function testRefreshWithToken()
    {
        $user  = User::factory()->create();
        $token = auth('jwt')->login($user);

        $uri      = route('api.v1.auth.refresh.store');
        $response = $this->withToken($token)->json('POST', $uri);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ])
            ->assertJsonPath('token_type', 'bearer');
    }

    /**
     * 同一秒內換發會拿到**逐字相同**的 token：claims 只有 sub / iat / exp，三個都以秒
     * 為單位，同一秒重發自然得到同一個簽章。這不是 bug，前端覆寫同樣的字串沒有影響。
     * 所以「換發成功」不能用 token 字串有沒有變來判斷，要看有效期有沒有往後延。
     */
    public function testRefreshExtendsExpiration()
    {
        $user = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00'));
        $token = auth('jwt')->login($user);

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:01'));
        $refreshed = $this->withToken($token)
            ->json('POST', route('api.v1.auth.refresh.store'))
            ->json('access_token');

        Carbon::setTestNow();

        $this->assertNotEquals($token, $refreshed);
        $this->assertSame(
            $this->payload($token)['exp'] + 1,
            $this->payload($refreshed)['exp']
        );
    }

    /**
     * 回應裡的 expires_in 是 config('jwt.ttl') 算出來的字面值，跟 token 裡有沒有 exp
     * 無關——曾經換發出來的 token 完全沒有 exp、永不過期，而只看回應的測試照樣是綠的。
     * 所以這裡一定要拆開 token 本身來看。
     */
    public function testRefreshedTokenCarriesExpiration()
    {
        $user  = User::factory()->create();
        $token = auth('jwt')->login($user);

        $uri     = route('api.v1.auth.refresh.store');
        $issued  = Carbon::now();
        $payload = $this->payload(
            $this->withToken($token)->json('POST', $uri)->json('access_token')
        );

        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertEquals((string) $user->id, (string) $payload['sub']);

        $ttl = (int) config('jwt.ttl') * 60;

        // 前端用 exp - iat 反推 TTL 再取百分比當換發門檻，兩個 claim 的差必須就是 TTL。
        $this->assertSame($ttl, $payload['exp'] - $payload['iat']);
        $this->assertEqualsWithDelta($issued->timestamp + $ttl, $payload['exp'], 5);
    }

    public function testRefreshedTokenIsAccepted()
    {
        $user  = User::factory()->create();
        $token = auth('jwt')->login($user);

        $refreshed = $this->withToken($token)
            ->json('POST', route('api.v1.auth.refresh.store'))
            ->json('access_token');

        $this->withToken($refreshed)
            ->json('GET', route('api.v1.users.index'))
            ->assertStatus(200);
    }
}
