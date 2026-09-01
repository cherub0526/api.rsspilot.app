<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Media;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use App\Jobs\Media\DailyDigestJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Console\Commands\Media\Notify
 */
class NotifyTest extends TestCase
{
    use RefreshDatabase;

    public function testDispatchesADigestJobForEveryUserWithANotifyingSubscription(): void
    {
        Queue::fake();

        $notified = $this->userSubscribedTo(true);
        $muted = $this->userSubscribedTo(false);
        User::factory()->create();

        $this->artisan('media:notify')->run();

        Queue::assertPushed(DailyDigestJob::class, 1);
        Queue::assertPushed(fn (DailyDigestJob $job) => $job->userId === $notified->id);
        Queue::assertNotPushed(fn (DailyDigestJob $job) => $job->userId === $muted->id);
    }

    public function testDefaultsToToday(): void
    {
        Queue::fake();

        $this->userSubscribedTo(true);

        $this->artisan('media:notify')->run();

        Queue::assertPushed(fn (DailyDigestJob $job) => $job->date === Carbon::today()->toDateString());
    }

    public function testDateOptionOverridesToday(): void
    {
        Queue::fake();

        $this->userSubscribedTo(true);

        $this->artisan('media:notify', ['--date' => '2026-08-01'])->run();

        Queue::assertPushed(fn (DailyDigestJob $job) => $job->date === '2026-08-01');
    }

    public function testInvalidDateDispatchesNothing(): void
    {
        Queue::fake();

        $this->userSubscribedTo(true);

        $this->artisan('media:notify', ['--date' => 'not-a-date'])->run();

        Queue::assertNothingPushed();
    }

    public function testUserOptionOnlyDispatchesTheMatchingUser(): void
    {
        Queue::fake();

        $target = $this->userSubscribedTo(true);
        $this->userSubscribedTo(true);

        $this->artisan('media:notify', ['--user' => $target->id])->run();

        Queue::assertPushed(DailyDigestJob::class, 1);
        Queue::assertPushed(fn (DailyDigestJob $job) => $job->userId === $target->id);
    }

    public function testQueuesOnTheNotifyQueue(): void
    {
        Queue::fake();

        $this->userSubscribedTo(true);

        $this->artisan('media:notify')->run();

        Queue::assertPushed(fn (DailyDigestJob $job) => $job->queue === 'media.notify');
    }

    private function userSubscribedTo(bool $notify): User
    {
        $user = User::factory()->create();
        $source = Source::factory()->create();
        $user->sources()->attach($source->id, ['notify' => $notify]);

        return $user;
    }
}
