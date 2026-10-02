<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Setting;
use Hyperf\Database\Exception\QueryException;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class UserSettingTest extends TestCase
{
    use RefreshDatabase;

    public function testCreatingAUserCreatesItsSetting(): void
    {
        $user = User::factory()->create();

        $this->assertSame(1, Setting::query()->where('user_id', $user->id)->count());
        // 空設定代表「沒有偏好」：uiLocale() 仍是 null，SetLocale 才會退回 Accept-Language。
        $this->assertSame([], $user->setting()->first()->data);
        $this->assertNull($user->uiLocale());
    }

    public function testAUserCannotHaveTwoSettings(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        Setting::create(['user_id' => $user->id, 'data' => []]);
    }

    public function testSeedUiLocaleKeepsTheSingleSetting(): void
    {
        $user = User::factory()->create();

        $user->seedUiLocale('zh-TW');

        $this->assertSame(1, Setting::query()->where('user_id', $user->id)->count());
        $this->assertSame('zh-TW', $user->uiLocale());
    }
}
