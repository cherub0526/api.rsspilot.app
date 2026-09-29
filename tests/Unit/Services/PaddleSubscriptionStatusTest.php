<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Subscription;
use App\Services\PaddleSubscriptionService;

/**
 * Paddle 訂閱狀態 → 我們自己的 `status` 的對照。
 *
 * 這層的意義在於權限：`Subscription::scopeActive()` 只認 `active` 與還沒到期的
 * `trial`，所以這個 match 寫錯的下場不是顯示問題，是「沒付錢的人有權限」或
 * 「還在付錢的人被停權」。
 *
 * 與 freeMonthAction() 同樣抽成純函式才測得到——PaddleClient 一律自己 new 出
 * Paddle\SDK\Client，不經容器解析（見 PaddleControllerTest 的說明）。
 *
 * @internal
 * @coversNothing
 */
class PaddleSubscriptionStatusTest extends TestCase
{
    private PaddleSubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PaddleSubscriptionService();
    }

    /** 免費月期間。前端靠這個顯示 Trial 徽章。 */
    public function testTrialingMapsToTrial(): void
    {
        $this->assertSame(Subscription::STATUS_TRIAL, $this->service->statusFor('trialing'));
    }

    public function testActiveMapsToActive(): void
    {
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->service->statusFor('active'));
    }

    /**
     * dunning 期間**不能**停權。
     *
     * past_due 代表這一期扣款失敗、Paddle 正在自動重試（約兩週）。這段期間把人
     * 停權的話，卡片過期這種小事會讓還在付錢的客人當場失去服務。真的收不到時
     * Paddle 會再送一則 subscription.canceled，那時才轉 canceled。
     */
    public function testPastDueStaysActiveSoDunningDoesNotRevokeAccess(): void
    {
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->service->statusFor('past_due'));
    }

    public function testCanceledMapsToCanceled(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('canceled'));
    }

    /**
     * 我們自己沒有暫停的流程，但有人從 Dashboard 按下去時不能讀成「還在訂閱」——
     * Paddle 暫停期間不計費，權限就該停。
     */
    public function testPausedMapsToCanceled(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('paused'));
    }

    public function testInactiveMapsToCanceled(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('inactive'));
    }

    /**
     * 未知狀態要落在 canceled 而不是 active。Paddle 之後新增狀態時，寧可少給權限
     * 也不要因為 default 落在 active 而把不該有權限的人放進來。
     */
    public function testAnUnknownStatusFailsClosed(): void
    {
        $this->assertSame(Subscription::STATUS_CANCELED, $this->service->statusFor('some_future_status'));
    }
}
