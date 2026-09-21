<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Queue;
use App\Jobs\Media\VideoTranscriberStartJob;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Console\Commands\VideoTranscriber\Start
 */
class StartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * userinfo 回一個正常的使用者，等同「登入狀態是好的」。
     *
     * **每一個案例都要自己呼叫一次**，不要收進 setUp()：Http 的 stub 是先註冊
     * 先贏，擺在 setUp 會把個別案例想模擬的失敗情境整個蓋掉。
     *
     * 而沒有 fake 的話測試會真的對外送請求——那組帳號只允許單一裝置登入，
     * 測試跑一次就把線上環境的 session 踢掉（見
     * docs/lore/transcription/pitfalls.md）。
     */
    private function fakeAuthenticated(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/userinfo*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['user_id' => 1807643, 'email' => 'tester@example.test', 'user_name' => 'Tester'],
            ], 200),
        ]);
    }

    /**
     * 登入狀態壞掉時一筆都不派。
     *
     * 不擋的話指令會照樣印出 "Starting transcription"、退出碼 0，真正的失敗
     * 要到 worker 才發生——每一筆各自撞 auth、退避 300 秒重試到時限為止。
     */
    public function testDispatchesNothingWhenTheSessionIsBroken(): void
    {
        Queue::fake();

        Http::fake([
            'videotranscriber.ai/api/v1/userinfo*'         => Http::response(['code' => 100002], 401),
            'videotranscriber.ai/api/v1/auth/email/login*' => Http::response(['code' => 100001, 'message' => 'invalid'], 200),
        ]);

        Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $exitCode = $this->artisan('videotranscriber:start')->run();

        $this->assertSame(1, $exitCode, '登入狀態壞掉時要以非零退出碼收場');
        Queue::assertNotPushed(VideoTranscriberStartJob::class);
    }

    /** token 過期但重新登入成功時照常派工——順帶把 token 續上。 */
    public function testLogsInAgainWhenTheTokenExpiredAndThenDispatches(): void
    {
        Queue::fake();

        Http::fake([
            'videotranscriber.ai/api/v1/userinfo*' => Http::sequence()
                ->push(['code' => 100002], 401)
                ->push([
                    'code' => 100000,
                    'data' => ['user_id' => 1807643, 'email' => 'tester@example.test', 'user_name' => 'Tester'],
                ], 200),
            'videotranscriber.ai/api/v1/auth/email/login*' => Http::response([
                'code' => 100000,
                'data' => ['access_token' => 'fresh-token'],
            ], 200),
        ]);

        Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->assertSame(0, $this->artisan('videotranscriber:start')->run());

        Queue::assertPushed(VideoTranscriberStartJob::class, 1);
    }

    public function testDispatchesAJobForEveryCreatedMedia(): void
    {
        Queue::fake();

        $this->fakeAuthenticated();

        $created1 = Media::factory()->create(['status' => Media::STATUS_CREATED]);
        $created2 = Media::factory()->create(['status' => Media::STATUS_CREATED]);
        $notCreated = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);

        $this->artisan('videotranscriber:start')->run();

        Queue::assertPushed(VideoTranscriberStartJob::class, 2);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $created1->id);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $created2->id);
        Queue::assertNotPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $notCreated->id);
    }

    public function testIdOptionOnlyDispatchesTheMatchingMedia(): void
    {
        Queue::fake();

        $this->fakeAuthenticated();

        $target = Media::factory()->create(['status' => Media::STATUS_CREATED]);
        Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:start', ['--id' => $target->id])->run();

        Queue::assertPushed(VideoTranscriberStartJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $target->id);
    }

    public function testIdOptionIgnoresTheMediaStatus(): void
    {
        Queue::fake();

        $this->fakeAuthenticated();

        $failed = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBE_FAILED]);

        $this->artisan('videotranscriber:start', ['--id' => $failed->id])->run();

        Queue::assertPushed(VideoTranscriberStartJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $failed->id);
    }

    public function testDoesNothingWhenNoMediaIsCreated(): void
    {
        Queue::fake();

        $this->fakeAuthenticated();

        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $this->artisan('videotranscriber:start')->run();

        Queue::assertNothingPushed();
    }

    public function testDispatchesNothingWhenAlreadyAtTheConcurrentLimit(): void
    {
        Queue::fake();

        $this->fakeAuthenticated();

        Media::factory()->count(5)->create(['status' => Media::STATUS_TRANSCRIBING]);
        Media::factory()->count(3)->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:start')->run();

        Queue::assertNothingPushed();
    }

    public function testOnlyDispatchesUpToTheRemainingSlots(): void
    {
        Queue::fake();

        $this->fakeAuthenticated();

        Media::factory()->count(3)->create(['status' => Media::STATUS_TRANSCRIBING]);
        Media::factory()->count(4)->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:start')->run();

        // 5 個名額扣掉 3 個在途，只剩 2 個。其餘留給下一次執行。
        Queue::assertPushed(VideoTranscriberStartJob::class, 2);
    }

    public function testIdOptionBypassesTheConcurrentLimit(): void
    {
        Queue::fake();

        $this->fakeAuthenticated();

        Media::factory()->count(5)->create(['status' => Media::STATUS_TRANSCRIBING]);
        $target = Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:start', ['--id' => $target->id])->run();

        // 指名 media 是明確的人工覆寫，跟略過狀態篩選同樣的理由。
        Queue::assertPushed(VideoTranscriberStartJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $target->id);
    }
}
