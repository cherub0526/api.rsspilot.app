<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\Caption;
use App\Models\CustomPrompt;
use App\Models\Subscription;
use Hypervel\Support\Facades\DB;

/**
 * 自訂摘要測試共用的情境：一位付費使用者、他訂閱的來源、綁定該來源的提示詞，
 * 以及一支「綁定之後才進來、已有字幕」的影片。各案例再從這個基準拿掉一個條件。
 */
trait BuildsCustomSummaryScenario
{
    protected User $user;

    protected Source $source;

    protected CustomPrompt $prompt;

    protected function givenAPaidUserWithABoundSource(bool $customSummaryEnabled = true): void
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'custom_summary_enabled' => $customSummaryEnabled,
        ]));
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 12.99,
        ]));

        $this->user = User::factory()->create();

        Subscription::factory()->create([
            'user_id'        => $this->user->id,
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
            'status'         => Subscription::STATUS_ACTIVE,
            'next_date'      => now()->addMonth(),
        ]);

        $this->source = Source::factory()->create();
        $this->user->sources()->attach($this->source->id);

        $this->prompt = CustomPrompt::query()->create([
            'user_id' => $this->user->id,
            'title'   => 'Bullet notes',
            'content' => 'Summarise as bullet notes.',
        ]);
        $this->prompt->sources()->attach($this->source->id);
    }

    /** 已有字幕的影片；$createdAt 用來模擬「綁定之前就存在」的舊影片。 */
    protected function givenACaptionedVideo(?string $createdAt = null, ?Source $source = null): Media
    {
        $media = Media::factory()->create([
            'source_id' => ($source ?? $this->source)->id,
            'status'    => Media::STATUS_SUMMARIZED,
        ]);

        Caption::factory()->create([
            'media_id' => $media->id,
            'locale'   => Caption::LOCAL_EN,
            'primary'  => true,
            'text'     => 'the transcript',
        ]);

        if ($createdAt !== null) {
            // created_at 不在 fillable 裡，factory 設不進去，直接改資料列。
            DB::table('media')->where('id', $media->id)->update(['created_at' => $createdAt]);
            $media->refresh();
        }

        return $media;
    }
}
