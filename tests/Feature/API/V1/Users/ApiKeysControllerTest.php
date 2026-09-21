<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Users;

use Tests\TestCase;
use App\Models\User;
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
        $this->fakeLogin();

        $this->json('POST', route('api.v1.users.api-keys.store'), ['name' => '  '])
            ->assertStatus(422);
    }

    /** 上限擋的是無限產生，不是正常使用。 */
    public function testStoreStopsAtTheKeyLimit(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        for ($i = 0; $i < 10; ++$i) {
            $user->createToken("key {$i}");
        }

        $this->json('POST', route('api.v1.users.api-keys.store'), ['name' => '第 11 把'])
            ->assertStatus(422);
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
