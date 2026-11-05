<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Auth;

use Tests\TestCase;
use App\Models\User;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 打開 sanctum 的 token 快取時，刪掉的 API key 也要立刻失效：
 * - 重設密碼、刪除帳號時的 `User::invalidateTokens()`（token-invalidation T01 security r1 Low-1）
 * - 使用者手動刪除 `DELETE /v1/users/api-keys/{id}`（同一個問題，pm 併入 T01 r2）.
 *
 * sanctum 只在 PersonalAccessToken 的 deleting／updating 事件裡清快取；批次 delete 不會觸發事件，
 * 快取裡的 key 就會一直有效，直到 `sanctum.cache.ttl` 到期。
 *
 * @internal
 * @coversNothing
 */
class ApiKeyCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.cache.enabled' => true, 'sanctum.cache.ttl' => 3600]);
    }

    public function testInvalidatedApiKeysAreNotServedFromTheCache(): void
    {
        $user = User::factory()->create();
        $first = $user->createToken('claude')->plainTextToken;
        $second = $user->createToken('cursor')->plainTextToken;

        // 先查一次，兩把都進快取
        $this->assertNotNull(PersonalAccessToken::findToken($first));
        $this->assertNotNull(PersonalAccessToken::findToken($second));

        $user->invalidateTokens();

        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->assertNull(PersonalAccessToken::findToken($second));
    }

    public function testAnotherUsersCachedKeyKeepsWorking(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $key = $other->createToken('claude')->plainTextToken;
        $this->assertNotNull(PersonalAccessToken::findToken($key));

        $user->invalidateTokens();

        $this->assertNotNull(PersonalAccessToken::findToken($key));
    }

    public function testADeletedApiKeyIsNotServedFromTheCache(): void
    {
        $user = $this->fakeLogin();
        $deleted = $user->createToken('claude');
        $kept = $user->createToken('cursor')->plainTextToken;

        $this->assertNotNull(PersonalAccessToken::findToken($deleted->plainTextToken));
        $this->assertNotNull(PersonalAccessToken::findToken($kept));

        $this->json('DELETE', route('api.v1.users.api-keys.destroy', ['id' => $deleted->accessToken->getKey()]))
            ->assertStatus(200);

        $this->assertNull(PersonalAccessToken::findToken($deleted->plainTextToken));
        $this->assertNotNull(PersonalAccessToken::findToken($kept), 'only the deleted key is affected');
    }

    public function testDeletingSomeoneElsesKeyIsStillNotFound(): void
    {
        $other = User::factory()->create();
        $key = $other->createToken('claude');
        $this->assertNotNull(PersonalAccessToken::findToken($key->plainTextToken));

        $this->fakeLogin();

        $this->json('DELETE', route('api.v1.users.api-keys.destroy', ['id' => $key->accessToken->getKey()]))
            ->assertStatus(404);

        $this->assertNotNull(PersonalAccessToken::findToken($key->plainTextToken));
    }
}
