<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Tests\TestCase;
use App\Models\User;
use App\Models\Setting;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 用 settings 端點的 422 訊息當觀測點——它是少數會回傳翻譯字串的回應。
 * 斷言直接比對 lang/ 裡的字面值而不是 __()，因為 __() 在測試進程裡會受
 * middleware 剛設過的語系影響，拿它當期望值等於用被測物驗證自己。
 *
 * @internal
 * @coversNothing
 */
class SetLocaleTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE_EN = 'The selected locale is invalid.';
    private const MESSAGE_ZH_TW = '選擇的介面語系不支援。';
    private const MESSAGE_ZH_CN = '选择的界面语系不支持。';

    public function testUsesSavedUserLocale(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        Setting::create(['user_id' => $user->id, 'data' => ['locale' => 'zh-TW']]);

        $this->assertEquals(self::MESSAGE_ZH_TW, $this->invalidLocaleMessage());
    }

    public function testFallsBackToAcceptLanguageWhenUserHasNoSetting(): void
    {
        $this->fakeLogin();

        $this->assertEquals(
            self::MESSAGE_ZH_CN,
            $this->invalidLocaleMessage(['Accept-Language' => 'zh-CN'])
        );
    }

    public function testSavedLocaleWinsOverAcceptLanguage(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        Setting::create(['user_id' => $user->id, 'data' => ['locale' => 'zh-TW']]);

        $this->assertEquals(
            self::MESSAGE_ZH_TW,
            $this->invalidLocaleMessage(['Accept-Language' => 'zh-CN'])
        );
    }

    public function testIgnoresSavedLocaleThatIsNoLongerSupported(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        Setting::create(['user_id' => $user->id, 'data' => ['locale' => 'ja']]);

        $this->assertEquals(
            self::MESSAGE_ZH_CN,
            $this->invalidLocaleMessage(['Accept-Language' => 'zh-CN'])
        );
    }

    public function testFallsBackToDefaultWhenNeitherIsPresent(): void
    {
        $this->fakeLogin();

        $this->assertEquals(self::MESSAGE_EN, $this->invalidLocaleMessage());
    }

    private function invalidLocaleMessage(array $headers = []): string
    {
        return $this->json(
            'PUT',
            route('api.v1.settings.update'),
            ['locale' => 'not-a-real-locale'],
            $headers
        )->assertStatus(422)->json('messages')['locale'][0];
    }
}
