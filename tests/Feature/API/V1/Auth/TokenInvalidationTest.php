<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Auth;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use Hyperf\Context\Context;
use App\Mail\ResetPasswordMail;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Mail;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * token-invalidation（specs/token-invalidation/acceptance.feature S01–S04）。
 *
 * 重設密碼之後，之前簽發的 JWT（包括換發出來的）一律 401，這個使用者的 API key 也失效；
 * 重設後新拿到的 JWT 可以用，其他使用者不受影響。
 *
 * 時間用 Carbon::setTestNow 控制：JWT 的 iat 取自 Carbon（見 RefreshControllerTest）。
 * 每個帶 token 的請求前都清掉 JwtGuard 放在 Context 的快取（{@see self::freshRequest()}），
 * 正式環境每個請求是新的 coroutine，測試裡同一個 coroutine 連發會讀到上一次的結果。
 *
 * 沒有在這裡測的：
 * - S01 的 POST /oauth/authorize/decision：只在 chatgpt-plugin 分支上，rebase 後補。
 * - S04「登入狀態下修改密碼」：沒有這個端點（backend 2026-11-05 確認，PUT /v1/users 只收 name、email）。
 *
 * @internal
 * @coversNothing
 */
class TokenInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password';

    private const NEW_PASSWORD = 'brand-new-password-1';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // S01：重設密碼之前簽發的 JWT 一律失效
    // -------------------------------------------------------------------------

    public function testS01AJwtIssuedBeforeAPasswordResetIsRejectedByUsersIndex(): void
    {
        $user = User::factory()->create();
        $old = $this->issueJwt($user);
        $this->getUser($old)->assertStatus(200);

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->getUser($old)->assertStatus(401);
    }

    public function testS01AJwtIssuedBeforeAPasswordResetCannotBeRefreshed(): void
    {
        $user = User::factory()->create();
        $old = $this->issueJwt($user);

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->refresh($old)->assertStatus(401);
    }

    /** 換發鏈要斷掉：重設前換發出來的 token，iat 雖然比較新，仍然早於重設。 */
    public function testS01ATokenRefreshedBeforeTheResetIsAlsoRejected(): void
    {
        $user = User::factory()->create();
        $old = $this->issueJwt($user);

        $this->at('10:10:00');
        $refreshed = $this->refresh($old)->assertStatus(200)->json('access_token');

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->getUser($refreshed)->assertStatus(401);
        $this->refresh($refreshed)->assertStatus(401);
    }

    /** 早 1 秒簽發的 token 也不行（邊界）。 */
    public function testS01AJwtIssuedOneSecondBeforeTheResetIsRejected(): void
    {
        $user = User::factory()->create();

        $this->at('10:29:59');
        $old = $this->issueJwt($user);

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->getUser($old)->assertStatus(401);
    }

    /** 重設之後過了很久，舊 token 仍然不能用（不是只擋一陣子）。 */
    public function testS01TheOldJwtStaysRejectedLaterOn(): void
    {
        $user = User::factory()->create();
        $old = $this->issueJwt($user);

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->at('11:50:00');
        $this->getUser($old)->assertStatus(401);
    }

    /** 401 的形狀和其他未登入的 401 一樣，不透露「因為重設密碼」。 */
    public function testS01TheRejectionLooksLikeAnyOtherUnauthenticatedResponse(): void
    {
        $user = User::factory()->create();
        $old = $this->issueJwt($user);

        $this->at('10:30:00');
        $this->resetPassword($user);

        $rejected = $this->getUser($old)->assertStatus(401);
        $this->freshRequest();
        $anonymous = $this->json('GET', route('api.v1.users.index'))->assertStatus(401);

        $this->assertSame($anonymous->getContent(), $rejected->getContent());
        $this->assertSame($anonymous->getHeaderLine('Content-Type'), $rejected->getHeaderLine('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // S02：重設密碼之後 API key 失效
    // -------------------------------------------------------------------------

    public function testS02AnApiKeyCreatedBeforeTheResetIsRejectedByMcp(): void
    {
        $this->createMcpPlan();
        $user = User::factory()->create();
        $key = $user->createToken('key1')->plainTextToken;
        $this->mcp($key)->assertStatus(200);

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->mcp($key)->assertStatus(401);
    }

    public function testS02TheApiKeyListIsEmptyAfterTheReset(): void
    {
        $this->createMcpPlan();
        $user = User::factory()->create();
        $user->createToken('key1');
        $user->createToken('key2');

        $this->at('10:30:00');
        $new = $this->resetPassword($user)->json('access_token');

        $this->freshRequest();
        $this->withToken($new)
            ->json('GET', route('api.v1.users.api-keys.index'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /** 重設之後新產生的 API key 可以正常使用。 */
    public function testS02AnApiKeyCreatedAfterTheResetWorks(): void
    {
        $this->createMcpPlan();
        $user = User::factory()->create();

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->at('10:31:00');
        $key = $user->fresh()->createToken('key-after')->plainTextToken;

        $this->mcp($key)->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // S03：新登入正常，其他人不受影響
    // -------------------------------------------------------------------------

    /** 重設回應本身發的新 token，和 tokens_invalid_before 在同一秒，不能被自己擋掉。 */
    public function testS03TheTokenReturnedByTheResetResponseWorks(): void
    {
        $user = User::factory()->create();

        $this->at('10:30:00');
        $new = $this->resetPassword($user)->assertStatus(200)->json('access_token');

        $this->getUser($new)->assertStatus(200);
        $this->refresh($new)->assertStatus(200);
    }

    public function testS03LoggingInAgainInTheSameSecondAsTheResetWorks(): void
    {
        $user = User::factory()->create();

        $this->at('10:30:00');
        $this->resetPassword($user);
        $new = $this->login($user, self::NEW_PASSWORD);

        $this->getUser($new)->assertStatus(200);
    }

    public function testS03LoggingInAgainLaterWorks(): void
    {
        $user = User::factory()->create();

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->at('10:45:00');
        $new = $this->login($user, self::NEW_PASSWORD);

        $this->getUser($new)->assertStatus(200);
        $this->refresh($new)->assertStatus(200);
    }

    public function testS03AnotherUsersJwtAndApiKeyAreUnaffected(): void
    {
        $this->createMcpPlan();
        $u = User::factory()->create();
        $v = User::factory()->create();
        $vJwt = $this->issueJwt($v);
        $vKey = $v->createToken('v-key')->plainTextToken;

        $this->at('10:30:00');
        $this->resetPassword($u);

        $this->getUser($vJwt)->assertStatus(200)->assertJsonPath('id', $v->id);
        $this->mcp($vKey)->assertStatus(200);
        $this->assertSame(1, $v->tokens()->count());
    }

    /** 第二次重設會讓第一次重設後拿到的 token 也失效。 */
    public function testS03ASecondResetInvalidatesTheTokenFromTheFirst(): void
    {
        $user = User::factory()->create();

        $this->at('10:30:00');
        $first = $this->resetPassword($user)->json('access_token');

        $this->at('11:00:00');
        $second = $this->resetPassword($user->fresh(), self::NEW_PASSWORD, 'another-password-2')
            ->json('access_token');

        $this->getUser($first)->assertStatus(401);
        $this->getUser($second)->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // 只有「重設成功」才會作廢（qa 補的負向測試）
    // -------------------------------------------------------------------------

    /** 只是申請重設信（任何人都能替別人申請）不能把本人踢出去。 */
    public function testRequestingAResetEmailDoesNotInvalidateAnything(): void
    {
        $this->createMcpPlan();
        $user = User::factory()->create();
        $old = $this->issueJwt($user);
        $key = $user->createToken('key1')->plainTextToken;

        $this->at('10:30:00');
        $this->json('POST', route('api.v1.auth.forgot-password.store'), ['email' => $user->email])
            ->assertStatus(200);

        $this->getUser($old)->assertStatus(200);
        $this->mcp($key)->assertStatus(200);
    }

    /** 連結被竄改、重設失敗時，什麼都不變。 */
    public function testAFailedResetDoesNotInvalidateAnything(): void
    {
        $this->createMcpPlan();
        $user = User::factory()->create();
        $old = $this->issueJwt($user);
        $key = $user->createToken('key1')->plainTextToken;

        $this->at('10:30:00');
        $params = $this->resetLinkParams($user);
        $params['signature'] = str_repeat('0', 64);
        $this->json('PUT', route('api.v1.auth.forgot-password.update'), $params + [
            'password'              => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->getUser($old)->assertStatus(200);
        $this->mcp($key)->assertStatus(200);
    }

    /** 一般的登入與登出不會作廢其他裝置的 token（不是「登出所有裝置」）。 */
    public function testLoggingInAgainWithoutAResetKeepsOtherTokensValid(): void
    {
        $user = User::factory()->create();
        $old = $this->issueJwt($user);

        $this->at('10:30:00');
        $this->login($user, self::PASSWORD);

        $this->getUser($old)->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // S04：刪除帳號
    // -------------------------------------------------------------------------

    /**
     * 刪除帳號：API 沒有刪除端點，正式環境只有 users:purge-unverified 用 query 批次刪
     * （backend 確認）。這裡用 model 的 delete()（soft delete）確認既有 JWT 與 API key 都不能再用。
     */
    public function testS04AfterTheAccountIsDeletedTheOldJwtIsRejected(): void
    {
        $user = User::factory()->create();
        $old = $this->issueJwt($user);

        $this->at('10:30:00');
        $user->delete();

        $this->getUser($old)->assertStatus(401);
        $this->refresh($old)->assertStatus(401);
    }

    public function testS04AfterTheAccountIsDeletedTheApiKeyIsRejected(): void
    {
        $this->createMcpPlan();
        $user = User::factory()->create();
        $key = $user->createToken('key1')->plainTextToken;

        $this->at('10:30:00');
        $user->delete();

        $this->mcp($key)->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // qa r1 補的邊界
    // -------------------------------------------------------------------------

    /** 帳號被刪除後又還原：刪除前的 JWT 與 API key 不能跟著復活，還原後重新登入可以用。 */
    public function testS04ARestoredAccountDoesNotReviveCredentialsFromBeforeTheDeletion(): void
    {
        $this->createMcpPlan();
        $user = User::factory()->create();
        $old = $this->issueJwt($user);
        $key = $user->createToken('key1')->plainTextToken;

        $this->at('10:30:00');
        $user->delete();
        $this->at('10:40:00');
        User::withTrashed()->findOrFail($user->id)->restore();

        $this->getUser($old)->assertStatus(401);
        $this->refresh($old)->assertStatus(401);
        $this->mcp($key)->assertStatus(401);
        $this->assertSame(0, $user->tokens()->count());

        $this->at('10:45:00');
        $this->getUser($this->login($user, self::PASSWORD))->assertStatus(200);
    }

    /** 永久刪除也會觸發 deleted 事件：invalidateTokens() 寫一筆已經不存在的列，不能丟例外。 */
    public function testForceDeletingAnAccountStillWorks(): void
    {
        $user = User::factory()->create();
        $user->createToken('key1');

        $user->forceDelete();

        $this->assertNull(User::withTrashed()->find($user->id));
        $this->assertSame(0, $user->tokens()->count());
    }

    /** tokens_invalid_before 是內部欄位，不出現在使用者資料的回應裡。 */
    public function testTheInvalidationTimeIsNotExposed(): void
    {
        $user = User::factory()->create();
        $this->at('10:30:00');
        $new = $this->resetPassword($user)->json('access_token');

        $this->assertNotNull($user->fresh()->tokens_invalid_before);
        $this->getUser($new)->assertStatus(200)->assertJsonMissingPath('tokens_invalid_before');
    }

    /** 同一個人在多台裝置上的 JWT 全部失效，不是只有最後一張。 */
    public function testS01EveryDeviceOfTheUserIsSignedOut(): void
    {
        $user = User::factory()->create();
        $laptop = $this->issueJwt($user);
        $this->at('10:05:00');
        $phone = $this->login($user, self::PASSWORD);

        $this->at('10:30:00');
        $this->resetPassword($user);

        $this->getUser($laptop)->assertStatus(401);
        $this->getUser($phone)->assertStatus(401);
    }

    /** qa r2：security:revoke-social-access --apply 之後舊 JWT 與 key 失效，但本人重新登入可以用（不是鎖帳號）。 */
    public function testAfterRevokeSocialAccessTheUserCanSignInAgain(): void
    {
        $this->createMcpPlan();
        $user = User::factory()->create();
        $old = $this->issueJwt($user);
        $key = $user->createToken('planted')->plainTextToken;

        $this->at('10:30:00');
        $this->artisan('security:revoke-social-access', ['userIds' => [$user->id], '--apply' => true])->assertExitCode(0);

        $this->getUser($old)->assertStatus(401);
        $this->mcp($key)->assertStatus(401);

        $this->at('10:31:00');
        $new = $this->login($user, self::PASSWORD);
        $this->getUser($new)->assertStatus(200);
        $this->refresh($new)->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 ' . $time));
    }

    /** 模擬一個新的請求：清掉 JwtGuard 快取在 Context 的使用者與 claims。 */
    private function freshRequest(?string $token = null): void
    {
        Context::destroy('auth.guards.jwt.result.default');
        Context::destroy('auth.guards.jwt.claims');
        if ($token !== null) {
            Context::destroy('auth.guards.jwt.result:' . md5($token));
        }
    }

    private function issueJwt(User $user): string
    {
        $token = auth('jwt')->login($user);
        $this->freshRequest($token);

        return $token;
    }

    private function getUser(string $token)
    {
        $this->freshRequest($token);

        return $this->withToken($token)->json('GET', route('api.v1.users.index'));
    }

    private function refresh(string $token)
    {
        $this->freshRequest($token);

        return $this->withToken($token)->json('POST', route('api.v1.auth.refresh.store'));
    }

    private function login(User $user, string $password): string
    {
        $this->freshRequest();
        $token = $this->json('POST', route('api.v1.auth.store'), [
            'email'    => $user->email,
            'password' => $password,
        ])->assertStatus(200)->json('access_token');
        $this->assertIsString($token);

        return $token;
    }

    /** 走真的重設流程：申請信 → 拿信裡的連結參數 → PUT 新密碼。回傳 PUT 的回應。 */
    private function resetPassword(User $user, string $current = self::PASSWORD, string $new = self::NEW_PASSWORD)
    {
        $this->assertTrue(Hash::check($current, $user->fresh()->password));
        $params = $this->resetLinkParams($user);
        $this->freshRequest();

        return $this->json('PUT', route('api.v1.auth.forgot-password.update'), $params + [
            'password'              => $new,
            'password_confirmation' => $new,
        ])->assertStatus(200);
    }

    /** @return array<string, string> */
    private function resetLinkParams(User $user): array
    {
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

    /** 沒有訂閱的人吃月費 0 元、開了 mcp 的方案（同 ServerControllerTest）。 */
    private function createMcpPlan(): void
    {
        Plan::withoutEvents(function (): void {
            $plan = Plan::factory()->create([
                'title'       => 'Pro',
                'mcp_enabled' => true,
                'status'      => Plan::STATUS_ACTIVE,
            ]);
            Price::create(['plan_id' => $plan->id, 'unit' => Price::UNIT_MONTHLY, 'price' => 0]);
        });
    }

    private function mcp(string $key)
    {
        $this->freshRequest();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $key])
            ->json('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    }
}
