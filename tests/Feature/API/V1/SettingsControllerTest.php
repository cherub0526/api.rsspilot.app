<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Setting;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testUpdateRequiresAuth(): void
    {
        $this->json('PUT', route('api.v1.settings.update'), ['ai' => ['language' => 'en']])
            ->assertStatus(401);
    }

    public function testUpdateValidatesRequiredFields(): void
    {
        $this->fakeLogin();

        $this->json('PUT', route('api.v1.settings.update'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['ai']]);
    }

    public function testUpdateValidatesLanguageIsSupported(): void
    {
        $this->fakeLogin();

        $response = $this->json('PUT', route('api.v1.settings.update'), ['ai' => ['language' => 'not-a-real-language']])
            ->assertStatus(422);

        $this->assertEquals(
            __('validators.settings.ai.language.in'),
            $response->json('messages')['ai.language'][0]
        );
    }

    public function testUpdateCreatesSettingWhenNoneExists(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $this->json('PUT', route('api.v1.settings.update'), ['ai' => ['language' => 'en']])
            ->assertStatus(200);

        $setting = Setting::where('user_id', $user->id)->first();
        $this->assertNotNull($setting);
        $this->assertEquals('en', $setting->data['ai']['language']);
    }

    public function testUpdateMergesIntoExistingSettingData(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        Setting::create([
            'user_id' => $user->id,
            'data'    => ['ai' => ['language' => 'en'], 'other' => ['flag' => true]],
        ]);

        $this->json('PUT', route('api.v1.settings.update'), ['ai' => ['language' => 'zh-TW']])
            ->assertStatus(200);

        $setting = Setting::where('user_id', $user->id)->first();
        $this->assertEquals('zh-TW', $setting->data['ai']['language']);
        $this->assertTrue($setting->data['other']['flag']);
    }
}
