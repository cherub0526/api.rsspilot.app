<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Subscriptions;

use Tests\TestCase;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 *
 * 只覆蓋不打 Creem API 的路徑。真正的開通需要查 Creem 的 checkout，
 * CreemClient 一律自己 new、換不掉，這條路由以 test 環境實測驗證。
 */
class CreemConfirmControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testRequiresAuthentication(): void
    {
        $this->json('POST', route('api.v1.subscriptions.creem.confirm.store'), ['checkout_id' => 'ch_x'])
            ->assertStatus(401);
    }

    public function testRequiresACheckoutId(): void
    {
        $this->fakeLogin();

        $this->json('POST', route('api.v1.subscriptions.creem.confirm.store'), [])
            ->assertStatus(422)
            ->assertJsonPath('messages.checkout_id.0', __('validators.controllers.subscription.checkout_id_required'));
    }

    /**
     * 不是 ch_ 開頭的值直接拒絕，不拿去打 Creem——redirect 參數是使用者可以任意
     * 改的，塞一個 sub_ 或亂碼進來不該換到任何東西。
     */
    public function testRejectsSomethingThatIsNotACheckoutId(): void
    {
        $this->fakeLogin();

        foreach (['sub_6pC2lNB6joCRQIZ1aMrTpi', '../../admin', '01m37jd2bwqy47cr9h45jmsqky'] as $id) {
            $this->json('POST', route('api.v1.subscriptions.creem.confirm.store'), ['checkout_id' => $id])
                ->assertStatus(422)
                ->assertJsonPath(
                    'messages.checkout_id.0',
                    __('validators.controllers.subscription.checkout_not_confirmable')
                );
        }
    }
}
