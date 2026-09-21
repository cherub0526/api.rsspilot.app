<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Users;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 使用者自己產生的 API key。
 *
 * @internal
 * @coversNothing
 */
class ApiKeysControllerTest extends TestCase
{
    use RefreshDatabase;

    /** 產生金鑰是 Pro 以上的功能，測試要先備一個有權限的方案。 */
    private function createPlan(bool $mcpEnabled = true): Plan
    {
        return Plan::withoutEvents(function () use ($mcpEnabled) {
            $plan = Plan::factory()->create([
                'title'       => $mcpEnabled ? 'Pro' : 'Free',
                'mcp_enabled' => $mcpEnabled,
                'status'      => Plan::STATUS_ACTIVE,
            ]);

            Price::create(['plan_id' => $plan->id, 'unit' => Price::UNIT_MONTHLY, 'price' => 0]);

            return $plan;
        });
    }

    public function testIndexRequiresAuth(): void
    {
        $this->json('GET', route('api.v1.users.api-keys.index'))->assertStatus(401);
    }

    /**
     * 明文只在建立時回一次，之後資料庫裡只剩雜湊。
     *
     * 這是 personal access token 值得信任的原因：連我們自己都讀不回來。
     */
    public function testStoreReturnsThePlaintextTokenOnlyOnce(): void
    {
        $this->createPlan();

        /** @var User $user */
        $user = $this->fakeLogin();

        $response = $this->json('POST', route('api.v1.users.api-keys.store'), [
            'name' => 'Claude Desktop',
        ])->assertStatus(200);

        $plain = $response->json('token');

        $this->assertNotEmpty($plain);

        // 形狀是 `<token id>|<前綴><隨機字串>`——前綴在 `|` 之後，不是整串的開頭。
        // 它的作用是讓 GitHub 一類的 secret scanning 認得出這是一把憑證。
        [$tokenId, $secret] = explode('|', $plain, 2);
        $this->assertSame((string) $response->json('id'), $tokenId);
        $this->assertStringStartsWith('rsp_', $secret);
        $this->assertSame('Claude Desktop', $response->json('name'));

        // 列表不會再吐出明文
        $list = $this->json('GET', route('api.v1.users.api-keys.index'))->assertStatus(200);
        $this->assertCount(1, $list->json('data'));
        $this->assertArrayNotHasKey('token', $list->json('data')[0]);

        // 資料庫存的是雜湊，不是明文
        $stored = (string) $user->tokens()->first()->getAttribute('token');
        $this->assertNotSame($plain, $stored);
        $this->assertSame(64, strlen($stored), 'sanctum 存的是 sha256');
    }

    public function testStoreRejectsAnEmptyName(): void
    {
        $this->createPlan();
        $this->fakeLogin();

        $this->json('POST', route('api.v1.users.api-keys.store'), ['name' => '  '])
            ->assertStatus(422);
    }

    /** 上限擋的是無限產生，不是正常使用。 */
    public function testStoreStopsAtTheKeyLimit(): void
    {
        $this->createPlan();

        /** @var User $user */
        $user = $this->fakeLogin();

        for ($i = 0; $i < 10; ++$i) {
            $user->createToken("key {$i}");
        }

        $this->json('POST', route('api.v1.users.api-keys.store'), ['name' => '第 11 把'])
            ->assertStatus(422);
    }

    /**
     * 產生金鑰是 Pro 以上的功能。
     *
     * 免費方案拿到金鑰也用不了（/mcp 會擋），在這裡就講清楚比讓他貼進第三方
     * 工具之後收到一個看不懂的錯誤好。
     */
    public function testStoreRequiresAPlanWithMcp(): void
    {
        $this->createPlan(mcpEnabled: false);
        $this->fakeLogin();

        $this->json('POST', route('api.v1.users.api-keys.store'), ['name' => 'Claude Desktop'])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']]);
    }

    /** 已經有的金鑰仍然列得出來也刪得掉——降級之後要看得到自己有哪些東西。 */
    public function testListingAndRevokingStayOpenAfterADowngrade(): void
    {
        $this->createPlan(mcpEnabled: false);

        /** @var User $user */
        $user = $this->fakeLogin();
        $token = $user->createToken('降級前產生的');

        $this->json('GET', route('api.v1.users.api-keys.index'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->json('DELETE', route('api.v1.users.api-keys.destroy', ['id' => $token->accessToken->getKey()]))
            ->assertStatus(200);
    }

    public function testDestroyRevokesTheKey(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $token = $user->createToken('要刪的');

        $this->json('DELETE', route('api.v1.users.api-keys.destroy', ['id' => $token->accessToken->getKey()]))
            ->assertStatus(200);

        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * 只刪得掉自己的。查詢一律先綁住持有者，不是先找 token 再比對——後者少寫一個
     * 判斷就變成任何人都刪得掉任何一把。
     */
    public function testDestroyCannotTouchSomeoneElsesKey(): void
    {
        $other = User::factory()->create();
        $token = $other->createToken('別人的');

        $this->fakeLogin();

        $this->json('DELETE', route('api.v1.users.api-keys.destroy', ['id' => $token->accessToken->getKey()]))
            ->assertStatus(404);

        $this->assertSame(1, $other->tokens()->count());
    }
}
