<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\CreemSubscriptionService;

/**
 * 取消 Creem 訂閱時，手上可能只有結帳當下存的 checkout id（ch_…）。
 * 這組測試守的是「從 checkout 物件取出真正訂閱 id」這個判斷。
 *
 * @internal
 * @coversNothing
 */
class CreemCheckoutSubscriptionIdTest extends TestCase
{
    public function testRecognisesCheckoutIds(): void
    {
        $this->assertTrue(CreemSubscriptionService::isCheckoutId('ch_4l0N34kxo16AhRKUHFUuXr'));
        $this->assertFalse(CreemSubscriptionService::isCheckoutId('sub_6pC2lNB6joCRQIZ1aMrTpi'));
    }

    /** Creem 的 checkout 把訂閱嵌成物件。 */
    public function testReadsAnEmbeddedSubscriptionObject(): void
    {
        $this->assertSame(
            'sub_6pC2lNB6joCRQIZ1aMrTpi',
            CreemSubscriptionService::subscriptionIdFromCheckout([
                'id'           => 'ch_4l0N34kxo16AhRKUHFUuXr',
                'subscription' => ['id' => 'sub_6pC2lNB6joCRQIZ1aMrTpi', 'object' => 'subscription'],
            ])
        );
    }

    /** 沒展開時只給字串 id，也要接得住。 */
    public function testReadsAPlainSubscriptionId(): void
    {
        $this->assertSame(
            'sub_6pC2lNB6joCRQIZ1aMrTpi',
            CreemSubscriptionService::subscriptionIdFromCheckout(['subscription' => 'sub_6pC2lNB6joCRQIZ1aMrTpi'])
        );
    }

    /**
     * 沒付完款的 checkout 沒有訂閱，要回 null——不能把 checkout id 當成訂閱 id
     * 送去取消，也不能回傳一個不是 sub_ 開頭的怪值。
     */
    public function testReturnsNullWhenTheCheckoutHasNoSubscription(): void
    {
        $this->assertNull(CreemSubscriptionService::subscriptionIdFromCheckout(['id' => 'ch_x']));
        $this->assertNull(CreemSubscriptionService::subscriptionIdFromCheckout(['subscription' => null]));
        $this->assertNull(CreemSubscriptionService::subscriptionIdFromCheckout(['subscription' => ['id' => 'ch_x']]));
    }
}
