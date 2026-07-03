<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 *
 * Only the validation layer is covered here. A successful callback requires
 * a real Paddle API round-trip (PaddleClient always constructs a live
 * Paddle\SDK\Client — it is not container-resolved, so it cannot be swapped
 * for a test double without changing production code). Exercising that path
 * would mean making a real network call to Paddle in the test suite, which
 * this project's other webhook tests (see StripeControllerTest) deliberately
 * avoid.
 */
class PaddleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testStoreValidatesRequiredFields(): void
    {
        $this->json('POST', route('api.v1.webhook.paddle.store'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['event_id', 'event_type', 'occurred_at', 'notification_id', 'data']]);
    }

    public function testStoreValidatesEventType(): void
    {
        $params = [
            'event_id'        => 'evt_01h8bzakzx3nhsf0rr6jh6vj6g',
            'event_type'      => 'subscription.created',
            'occurred_at'     => '2024-01-01T00:00:00Z',
            'notification_id' => 'ntf_01h8bzakzx3nhsf0rr6jh6vj6g',
            'data'            => ['id' => 'txn_01h8bzakzx3nhsf0rr6jh6vj6g'],
        ];

        $this->json('POST', route('api.v1.webhook.paddle.store'), $params)
            ->assertStatus(422)
            ->assertJsonPath('messages.event_type.0', __('validators.paddle.event_type.in'));
    }

    public function testStoreValidatesDataIdRequired(): void
    {
        $params = [
            'event_id'        => 'evt_01h8bzakzx3nhsf0rr6jh6vj6g',
            'event_type'      => 'transaction.completed',
            'occurred_at'     => '2024-01-01T00:00:00Z',
            'notification_id' => 'ntf_01h8bzakzx3nhsf0rr6jh6vj6g',
            'data'            => [],
        ];

        $this->json('POST', route('api.v1.webhook.paddle.store'), $params)
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['data.id']]);
    }
}
