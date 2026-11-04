<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\Oauth;
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
}
