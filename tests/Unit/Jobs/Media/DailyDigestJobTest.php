<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\Summary;
use App\Mail\DailyDigestMail;
use Hypervel\Support\Facades\DB;
use App\Jobs\Media\DailyDigestJob;
use Hypervel\Support\Facades\Mail;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Jobs\Media\DailyDigestJob
 */
class DailyDigestJobTest extends TestCase
{
    use RefreshDatabase;

    public function testSendsTheDigestWithTodaysSummarisedMedia(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $source = $this->subscribe($user, true);
        $media = $this->mediaFor($user, $source);

        (new DailyDigestJob($user->id, Carbon::today()->toDateString()))->handle();

        // 直接看渲染結果，而不是翻 mailable 的內部集合——版型與 mailable 之間
        // 的變數命名衝突只有真的渲染過才看得出來。
        Mail::assertSent(
            DailyDigestMail::class,
            fn (DailyDigestMail $mail) => $mail->user->id === $user->id
                && str_contains($mail->render(), (string) $media->title)
                && str_contains($mail->render(), 'tldr')
        );
    }

    public function testUsesTheUsersOwnSummaryInTheirLocale(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $user->setting()->create(['data' => ['locale' => Summary::LOCALE_ZH_TW]]);
        $source = $this->subscribe($user, true);
        $media = $this->mediaFor($user, $source);
        $media->summaries()->whereNull('user_id')->update(['text' => ['short_summary' => 'shared summary']]);

        Summary::factory()->create([
            'media_id' => $media->id,
            'user_id'  => $user->id,
            'locale'   => Summary::LOCALE_ZH_TW,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['short_summary' => '我自己的摘要', 'long_summary' => ['key_points' => ['a']]],
        ]);

        (new DailyDigestJob($user->id, Carbon::today()->toDateString()))->handle();

        Mail::assertSent(
            DailyDigestMail::class,
            // 共用那筆的 short_summary 是 'shared'，出現它就代表挑錯了。
            fn (DailyDigestMail $mail) => str_contains($mail->render(), '我自己的摘要')
                && !str_contains($mail->render(), 'shared summary')
        );
    }

    public function testSkipsMediaAddedOnAnotherDay(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $source = $this->subscribe($user, true);
        $this->mediaFor($user, $source, addedAt: Carbon::today()->subDay());

        (new DailyDigestJob($user->id, Carbon::today()->toDateString()))->handle();

        Mail::assertNothingSent();
    }

    public function testSkipsMediaWithoutACompletedSummary(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $source = $this->subscribe($user, true);
        $this->mediaFor($user, $source, summaryStatus: Summary::STATUS_FAILED);

        (new DailyDigestJob($user->id, Carbon::today()->toDateString()))->handle();

        Mail::assertNothingSent();
    }

    public function testSkipsSourcesWithNotificationsOff(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $source = $this->subscribe($user, false);
        $this->mediaFor($user, $source);

        (new DailyDigestJob($user->id, Carbon::today()->toDateString()))->handle();

        Mail::assertNothingSent();
    }

    public function testSkipsMediaThatIsNotInTheUsersLibrary(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $source = $this->subscribe($user, true);

        $media = Media::factory()->create(['source_id' => $source->id]);
        Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => Summary::LOCALE_EN,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['short_summary' => 'tldr'],
        ]);

        (new DailyDigestJob($user->id, Carbon::today()->toDateString()))->handle();

        Mail::assertNothingSent();
    }

    public function testDoesNothingWhenTheUserIsGone(): void
    {
        Mail::fake();

        (new DailyDigestJob('01jz0000000000000000000000', Carbon::today()->toDateString()))->handle();

        Mail::assertNothingSent();
    }

    private function subscribe(User $user, bool $notify): Source
    {
        $source = Source::factory()->create();
        $user->sources()->attach($source->id, ['notify' => $notify]);

        return $source;
    }

    private function mediaFor(
        User $user,
        Source $source,
        ?Carbon $addedAt = null,
        string $summaryStatus = Summary::STATUS_COMPLETED,
    ): Media {
        $media = Media::factory()->create([
            'source_id' => $source->id,
            'status'    => Media::STATUS_SUMMARIZED,
        ]);

        Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => Summary::LOCALE_EN,
            'status'   => $summaryStatus,
            'text'     => ['short_summary' => 'tldr', 'long_summary' => ['key_points' => ['a']]],
        ]);

        $user->media()->attach($media->id);

        if ($addedAt) {
            DB::table('userables')
                ->where('user_id', $user->id)
                ->where('media_id', $media->id)
                ->update(['created_at' => $addedAt, 'updated_at' => $addedAt]);
        }

        return $media;
    }
}
