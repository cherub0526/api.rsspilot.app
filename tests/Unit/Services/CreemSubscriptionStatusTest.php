<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Subscription;
use App\Services\CreemSubscriptionService;

/**
 * Creem 訂閱狀態 → 我們自己的 `status` 的對照。
 *
 * 與 PaddleSubscriptionStatusTest 同一套判準：`Subscription::scopeActive()` 只認
 * `active` 與未到期的 `trial`，所以這個 match 寫錯的下場是權限問題——「沒付錢的人
 * 有權限」或「還在付錢的人被停權」——而不是顯示問題。
 *
 * @internal
 * @coversNothing
 */
class CreemSubscriptionStatusTest extends TestCase
{
    private CreemSubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CreemSubscriptionService();
    }

    public function testTrialingMapsToTrial(): void
    {
        $this->assertSame(Subscription::STATUS_TRIAL, $this->service->statusFor('trialing'));
    }

    public function testActiveMapsToActive(): void
    {
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->service->statusFor('active'));
    }

    /**
     * dunning 期間不能停權。past_due 時 Creem 還在重試，真的收不到才會送 unpaid。
     * 在這裡就停權等於卡片過期立刻讓付費客人斷線。
     */
    public function testPastDueStaysActiveSoDunningDoesNotRevokeAccess(): void
    {
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->service->statusFor('past_due'));
    }

    /** 重試用盡，這時才真的收不到錢。 */
    public function testUnpaidMapsToCanceled(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('unpaid'));
    }

    public function testExpiredMapsToCanceled(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('expired'));
    }

    public function testCanceledMapsToCanceled(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('canceled'));
    }

    public function testPausedMapsToCanceled(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('paused'));
    }

    /**
     * 未知狀態要落在 canceled 而不是 active——Creem 之後新增狀態時，寧可少給權限
     * 也不要因為 default 落在 active 而把不該有權限的人放進來。
     */
    public function testAnUnknownStatusFailsClosed(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('some_future_status'));
    }
}
