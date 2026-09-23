<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Http\Controllers\API\V1\Webhook\CreemController;

/**
 * @internal
 * @coversNothing
 *
 * 只覆蓋驗簽與分派兩層。成功的訂閱同步需要資料庫裡有對得上的訂閱，而更完整的
 * 路徑會碰到 Creem API（CreemClient 一律自己 new，不經容器解析，換不掉），
 * 讓測試對外發請求是這個專案明確避免的事——與 PaddleControllerTest 同樣的取捨。
 */
class CreemControllerTest extends TestCase
{
    use RefreshDatabase;

    /** 與 phpunit.xml.dist 的 CREEM_WEBHOOK_SECRET 一致。 */
    private const SECRET = 'creem_test_secret';

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'eventType' => 'subscription.canceled',
            'object'    => [
                'id'       => 'sub_creem_does_not_exist',
                'status'   => 'canceled',
                'metadata' => ['subscriptionId' => '01JCXYZ000000000000000000'],
            ],
        ], $overrides);
    }

    /**
     * Creem 的簽章是 HMAC-SHA256(raw body)，**不含時間戳**（Paddle 有）。
     *
     * rawBody 必須與測試客戶端實際送出的位元組完全一致，而 `TestClient::json()`
     * 用的是 `json_encode($data, JSON_UNESCAPED_UNICODE)`——flag 對不上就一定不過。
     *
     * @return array<string, string>
     */
    private function signatureFor(array $payload, string $secret = self::SECRET): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return ['creem-signature' => hash_hmac('sha256', $body, $secret)];
    }

    private function send(array $payload, array $headers)
    {
        return $this->createTestResponse(
            $this->getTestingClient()->json(
                'POST',
                route('api.v1.webhook.creem.store'),
                $payload,
                $headers
            )
        );
    }

    private function assertRejected($response): void
    {
        $response->assertStatus(422)->assertJsonPath('messages.signature.0', 'Invalid Creem webhook signature.');
    }

    // ================================================================

    public function testStoreRejectsARequestWithoutASignature(): void
    {
        $this->assertRejected($this->send($this->payload(), []));
    }

    public function testStoreRejectsAForgedSignature(): void
    {
        $this->assertRejected(
            $this->send($this->payload(), $this->signatureFor($this->payload(), 'wrong-secret'))
        );
    }

    /** 簽章正確但內容被動過 → 雜湊對不上。這才是驗簽真正要擋的攻擊。 */
    public function testStoreRejectsATamperedBody(): void
    {
        $headers = $this->signatureFor($this->payload());
        $tampered = $this->payload(['object' => ['id' => 'sub_attacker_controlled', 'status' => 'active']]);

        $this->assertRejected($this->send($tampered, $headers));
    }

    // ---- 驗簽通過之後的分派 ----

    /**
     * 十種訂閱事件都要走到 resolveSubscription()。
     *
     * 通過驗簽後會停在「找不到這筆訂閱」——這正是我們要的斷言：證明事件被收下
     * 並走到了對應那一步，而不是在分派時被當成不認得的事件默默放掉。
     */
    public function testStoreAcceptsEverySubscriptionLifecycleEvent(): void
    {
        foreach (CreemController::SUBSCRIPTION_EVENTS as $eventType) {
            $payload = $this->payload(['eventType' => $eventType]);

            $this->send($payload, $this->signatureFor($payload))
                ->assertStatus(422)
                ->assertJsonStructure(['messages' => ['subscription']]);
        }
    }

    /**
     * 沒訂閱處理的事件要回 200 而不是錯誤——回非 2xx 會讓 Creem 不斷重送一則
     * 我們本來就不打算處理的事件。
     */
    public function testStoreAcknowledgesUnhandledEventTypes(): void
    {
        $payload = $this->payload(['eventType' => 'refund.created']);

        $this->send($payload, $this->signatureFor($payload))->assertStatus(200);
    }

    /** checkout.completed 同樣不處理：訂閱狀態由後續的 subscription.* 帶來。 */
    public function testStoreAcknowledgesCheckoutCompleted(): void
    {
        $payload = $this->payload(['eventType' => CreemController::EVENT_CHECKOUT_COMPLETED]);

        $this->send($payload, $this->signatureFor($payload))->assertStatus(200);
    }

    /** 結構不對的內容要擋在解析階段，不能讓它走到下游變成 500。 */
    public function testStoreRejectsAMalformedPayload(): void
    {
        $payload = ['eventType' => 'subscription.canceled'];

        $this->send($payload, $this->signatureFor($payload))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['payload']]);
    }
}
