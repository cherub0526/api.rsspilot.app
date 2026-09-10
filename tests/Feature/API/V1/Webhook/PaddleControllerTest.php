<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 *
 * 這裡只覆蓋驗簽與驗證兩層。成功的 callback 需要真的打一次 Paddle API
 * （PaddleClient 一律自己 new 出 Paddle\SDK\Client，不經容器解析，所以無法在
 * 不改動正式程式碼的前提下換成測試替身），而讓測試對外發請求是這個專案明確
 * 避免的事——StripeControllerTest 也是同樣的取捨。
 */
class PaddleControllerTest extends TestCase
{
    use RefreshDatabase;

    /** 與 phpunit.xml.dist 的 PADDLE_WEBHOOK_SECRET_KEY 一致。 */
    private const SECRET = 'pdl_ntfset_test_secret';

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_id'        => 'evt_01h8bzakzx3nhsf0rr6jh6vj6g',
            'event_type'      => 'transaction.completed',
            'occurred_at'     => '2024-01-01T00:00:00Z',
            'notification_id' => 'ntf_01h8bzakzx3nhsf0rr6jh6vj6g',
            'data'            => ['id' => 'txn_01h8bzakzx3nhsf0rr6jh6vj6g'],
        ], $overrides);
    }

    /**
     * 依 Paddle 的規格簽名：HMAC-SHA256 of "{ts}:{rawBody}"。
     *
     * rawBody 必須與測試客戶端實際送出的位元組完全一致，而 `TestClient::json()`
     * 用的是 `json_encode($data, JSON_UNESCAPED_UNICODE)`——flag 對不上簽章就一定
     * 不過，這裡刻意寫死同一組。
     *
     * @return array<string, string>
     */
    private function signatureFor(array $payload, ?int $timestamp = null, string $secret = self::SECRET): array
    {
        $timestamp ??= time();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $hash = hash_hmac('sha256', "{$timestamp}:{$body}", $secret);

        return ['Paddle-Signature' => "ts={$timestamp};h1={$hash}"];
    }

    /**
     * 一定要送**真的 JSON body**。
     *
     * `$this->json('POST', ...)` 在這個框架裡其實走 `TestClient::post()`，body 會是
     * form-urlencoded（`http_build_query`），而驗簽算的是 raw body——用那條路送，
     * 測試簽的東西跟伺服器收到的根本不同，而且正式環境的 Paddle 送的是 JSON，
     * 等於測了一條production 不存在的路徑。`TestClient::json()` 才會把
     * `json_encode($data, JSON_UNESCAPED_UNICODE)` 當成 body。
     */
    private function send(array $payload, array $headers)
    {
        return $this->createTestResponse(
            $this->getTestingClient()->json(
                'POST',
                route('api.v1.webhook.paddle.store'),
                $payload,
                $headers
            )
        );
    }

    private function assertRejected($response): void
    {
        $response->assertStatus(422)->assertJsonPath('messages.signature.0', 'Invalid Paddle webhook signature.');
    }

    // ================================================================

    /**
     * 沒有簽章標頭時直接擋下——這是補這道驗證之前的狀態，任何人都打得進來。
     */
    public function testStoreRejectsARequestWithoutASignature(): void
    {
        $this->assertRejected($this->send($this->payload(), []));
    }

    public function testStoreRejectsAForgedSignature(): void
    {
        $this->assertRejected(
            $this->send($this->payload(), $this->signatureFor($this->payload(), null, 'wrong-secret'))
        );
    }

    /**
     * 簽章正確但內容被動過 → 雜湊對不上。這才是驗簽真正要擋的攻擊。
     */
    public function testStoreRejectsATamperedBody(): void
    {
        $headers = $this->signatureFor($this->payload());
        $tampered = $this->payload(['data' => ['id' => 'txn_attacker_controlled']]);

        $this->assertRejected($this->send($tampered, $headers));
    }

    /**
     * 超過容許時間差的重送要擋下，否則側錄到的合法請求可以無限重放。
     */
    public function testStoreRejectsAnExpiredTimestamp(): void
    {
        $payload = $this->payload();

        $this->assertRejected($this->send($payload, $this->signatureFor($payload, time() - 3600)));
    }

    /**
     * 時間差在容許範圍內就要放行——SDK 預設的 5 秒撐不住實務上的時鐘偏移，
     * 會把合法的 webhook 擋掉，所以這裡刻意用兩分鐘前的簽章。
     *
     * 用一份「簽章有效但內容不合法」的 payload：通過驗簽之後停在驗證層，
     * 拿到 data.id 的錯誤就證明簽章那關過了。用合法內容會繼續走到真正的
     * Paddle API 呼叫，那條路測不了（見 class docblock）。
     */
    public function testStoreAcceptsATimestampWithinTolerance(): void
    {
        $payload = $this->payload(['data' => []]);

        $this->send($payload, $this->signatureFor($payload, time() - 120))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['data.id']]);
    }

    // ---- 驗簽通過之後，原本的驗證層行為 ----

    public function testStoreValidatesRequiredFields(): void
    {
        $this->send([], $this->signatureFor([]))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['event_id', 'event_type', 'occurred_at', 'notification_id', 'data']]);
    }

    public function testStoreValidatesEventType(): void
    {
        $payload = $this->payload(['event_type' => 'subscription.created']);

        $this->send($payload, $this->signatureFor($payload))
            ->assertStatus(422)
            ->assertJsonPath('messages.event_type.0', __('validators.paddle.event_type.in'));
    }

    public function testStoreValidatesDataIdRequired(): void
    {
        $payload = $this->payload(['data' => []]);

        $this->send($payload, $this->signatureFor($payload))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['data.id']]);
    }
}
