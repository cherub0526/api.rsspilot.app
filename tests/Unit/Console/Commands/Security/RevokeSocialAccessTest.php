<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Security;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use App\Models\Oauth;
use Hyperf\Context\Context;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * security-hotfix-1 SR-2：撤銷舊 Google 登入端點可能已被利用的存取權。
 * 預設 dry-run，只有 --apply 才寫入，而且只動列出的 user。
 *
 * @internal
 * @coversNothing
 */
class RevokeSocialAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ATTACKER_SUB = 'attacker-sub';

    private const REAL_SUB = 'real-sub';

    /** Attack B：舊端點先埋了攻擊者的 provider_id，受害者之後經 OAuth 登入、以 email 合併。 */
    private function hijackedUser(): User
    {
        $user = User::factory()->create([
            'social_type' => User::SOCIAL_TYPE_GOOGLE,
            'provider_id' => self::ATTACKER_SUB,
            'email'       => 'victim@example.test',
        ]);
        $this->googleOauth($user, self::REAL_SUB);
        $user->createToken('planted key');

        return $user;
    }

    private function googleOauth(User $user, string $sub): void
    {
        Oauth::query()->create([
            'provider'    => User::SOCIAL_TYPE_GOOGLE,
            'provider_id' => $sub,
            'user_id'     => $user->id,
            'token'       => 'oauth-access-token',
        ]);
    }

    private function revoke(array $ids, bool $apply = false): int
    {
        return $this->artisan('security:revoke-social-access', array_filter([
            'userIds' => $ids,
            '--apply' => $apply ?: null,
        ]))->run();
    }

    public function testADryRunWritesNothing(): void
    {
        $user = $this->hijackedUser();

        $this->assertSame(0, $this->revoke([$user->id]));

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(self::ATTACKER_SUB, $user->refresh()->provider_id);
    }

    public function testApplyRevokesTokensAndRestoresTheProviderIdFromOauth(): void
    {
        $user = $this->hijackedUser();

        $this->assertSame(0, $this->revoke([$user->id], apply: true));

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(self::REAL_SUB, $user->refresh()->provider_id);
    }

    public function testOnlyTheListedUsersAreTouched(): void
    {
        $listed = $this->hijackedUser();
        $other = User::factory()->create(['social_type' => User::SOCIAL_TYPE_GOOGLE, 'provider_id' => 'other-planted']);
        $this->googleOauth($other, 'other-real');
        $other->createToken('other key');

        $this->revoke([$listed->id], apply: true);

        $this->assertSame(1, $other->tokens()->count());
        $this->assertSame('other-planted', $other->refresh()->provider_id);
    }

    /** Q1 的帳號（沒有 google oauths）只撤 token，provider_id 沒有可信的值可以改回去。 */
    public function testAUserWithoutGoogleOauthKeepsItsProviderId(): void
    {
        $user = User::factory()->create(['social_type' => User::SOCIAL_TYPE_GOOGLE, 'provider_id' => 'legacy-sub']);
        $user->createToken('key');

        $this->revoke([$user->id], apply: true);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame('legacy-sub', $user->refresh()->provider_id);
    }

    /** 兩組 google oauths 指向同一個帳號時無法判斷哪個才對：只撤 token，provider_id 留給人工判斷。 */
    public function testAnAmbiguousOauthLeavesTheProviderIdAlone(): void
    {
        $user = $this->hijackedUser();
        $this->googleOauth($user, 'second-real-sub');

        $this->revoke([$user->id], apply: true);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(self::ATTACKER_SUB, $user->refresh()->provider_id);
    }

    public function testADeletedAccountIsStillRevoked(): void
    {
        $user = $this->hijackedUser();
        $user->delete();

        $this->revoke([$user->id], apply: true);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(self::REAL_SUB, User::withTrashed()->find($user->id)?->provider_id);
    }

    public function testAnUnknownIdIsReportedWithoutFailing(): void
    {
        $user = $this->hijackedUser();

        $this->assertSame(0, $this->revoke(['01jzzzzzzzzzzzzzzzzzzzzzzz', $user->id], apply: true));

        $this->assertSame(0, $user->tokens()->count());
    }

    public function testTheOutputCarriesNoEmailsOrIdentifiers(): void
    {
        $user = $this->hijackedUser();

        $this->artisan('security:revoke-social-access', ['userIds' => [$user->id], '--apply' => true])
            ->doesntExpectOutputToContain('victim@example.test')
            ->doesntExpectOutputToContain(self::ATTACKER_SUB)
            ->doesntExpectOutputToContain(self::REAL_SUB)
            ->assertExitCode(0);
    }

    /**
     * 打開 sanctum 的 token 快取時，撤銷的 key 也要立刻失效：批次 delete 不會觸發 deleting 事件，
     * 快取不會被清（token-invalidation T01 r2，與 security r1 Low-1 同一個問題）。
     */
    public function testRevokedKeysAreNotServedFromTheSanctumCache(): void
    {
        config(['sanctum.cache.enabled' => true, 'sanctum.cache.ttl' => 3600]);

        $user = User::factory()->create();
        $first = $user->createToken('planted key')->plainTextToken;
        $second = $user->createToken('another key')->plainTextToken;
        $this->assertNotNull(PersonalAccessToken::findToken($first));
        $this->assertNotNull(PersonalAccessToken::findToken($second));

        $this->assertSame(0, $this->revoke([$user->id], apply: true));

        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->assertNull(PersonalAccessToken::findToken($second));
    }

    /** 撤銷也要作廢 JWT：被冒用的帳號，攻擊者手上可能還有登入憑證（token-invalidation T01 r2）。 */
    public function testApplyAlsoInvalidatesTheListedUsersJwts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00'));
        $user = $this->hijackedUser();
        $other = User::factory()->create();
        $jwt = auth('jwt')->login($user);
        $otherJwt = auth('jwt')->login($other);
        $this->getUsersWith($jwt)->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-11-05 10:30:00'));
        $this->assertSame(0, $this->revoke([$user->id], apply: true));

        $this->getUsersWith($jwt)->assertStatus(401);
        $this->getUsersWith($otherJwt)->assertStatus(200);

        Carbon::setTestNow();
    }

    public function testADryRunLeavesJwtsAlone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00'));
        $user = $this->hijackedUser();
        $jwt = auth('jwt')->login($user);

        Carbon::setTestNow(Carbon::parse('2026-11-05 10:30:00'));
        $this->assertSame(0, $this->revoke([$user->id]));

        $this->getUsersWith($jwt)->assertStatus(200);
        $this->assertNull($user->fresh()->tokens_invalid_before);

        Carbon::setTestNow();
    }

    public function testTheDryRunSaysEveryDeviceWillBeSignedOut(): void
    {
        $user = $this->hijackedUser();

        $this->artisan('security:revoke-social-access', ['userIds' => [$user->id]])
            ->expectsOutputToContain('會登出這個帳號的所有裝置')
            ->assertExitCode(0);
    }

    /** 每次都當成新的請求：清掉 jwt guard 快取在 Context 的使用者（同 TokenInvalidationTest）。 */
    private function getUsersWith(string $jwt)
    {
        Context::destroy('auth.guards.jwt.result.default');
        Context::destroy('auth.guards.jwt.claims');
        Context::destroy('auth.guards.jwt.result:' . md5($jwt));

        return $this->withToken($jwt)->json('GET', route('api.v1.users.index'));
    }
}
