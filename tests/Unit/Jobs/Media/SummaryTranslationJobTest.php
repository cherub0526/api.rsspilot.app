<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use RuntimeException;
use App\Models\Config;
use App\Models\Summary;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Support\Facades\Http;
use App\Jobs\Media\SummaryTranslationJob;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Jobs\Media\SummaryTranslationJob
 */
class SummaryTranslationJobTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'openrouter.ai/api/v1/chat/completions*';

    /** 這支 job 在 configs 兩張對照表裡的 key（反斜線換斜線）。 */
    private const PURPOSE_KEY = 'App/Jobs/Media/SummaryTranslationJob';

    /**
     * An OpenRouter reply carrying $content, with the model the free router
     * actually resolved to.
     */
    private function fakeCompletion(string $content, string $model = 'deepseek/deepseek-v4-flash-0731:free'): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'model'   => $model,
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ], 200),
        ]);
    }

    /**
     * @param array<int, string> $keyPoints
     */
    private function summaryJson(string $short, string $content, array $keyPoints = ['one']): string
    {
        return (string) json_encode([
            'short_summary' => $short,
            'long_summary'  => [
                'content'    => $content,
                'key_points' => $keyPoints,
                'keywords'   => ['alpha'],
            ],
        ]);
    }

    private function sourceSummary(string $locale = Summary::LOCALE_EN): Summary
    {
        $media = Media::factory()->create(['status' => Media::STATUS_SUMMARIZED]);

        return Summary::factory()->create([
            'media_id' => $media->id,
            'user_id'  => null,
            'locale'   => $locale,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => json_decode($this->summaryJson('the short one', '# Title'), true),
        ]);
    }

    public function testStoresTheTranslationAsItsOwnSummaryRow(): void
    {
        $this->fakeCompletion($this->summaryJson('簡短摘要', '# 標題', ['第一點']));

        $source = $this->sourceSummary();

        (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->handle();

        $translated = Summary::query()
            ->where('media_id', $source->media_id)
            ->where('locale', Summary::LOCALE_ZH_TW)
            ->first();

        $this->assertNotNull($translated);
        $this->assertNull($translated->user_id);
        $this->assertSame(Summary::STATUS_COMPLETED, $translated->status);
        $this->assertSame('簡短摘要', $translated->text['short_summary']);
        $this->assertSame('# 標題', $translated->text['long_summary']['content']);
        $this->assertSame(['第一點'], $translated->text['long_summary']['key_points']);

        // What the free router resolved to, not the slug we asked for — that is
        // the only record of which model produced a given translation.
        $this->assertSame('deepseek/deepseek-v4-flash-0731:free', $translated->ai_model);
    }

    public function testLeavesTheSourceSummaryAndTheMediaAlone(): void
    {
        $this->fakeCompletion($this->summaryJson('簡短摘要', '# 標題'));

        $source = $this->sourceSummary();

        (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->handle();

        $this->assertSame('# Title', $source->refresh()->text['long_summary']['content']);
        $this->assertSame(Summary::STATUS_COMPLETED, $source->status);
        $this->assertSame(Media::STATUS_SUMMARIZED, $source->media->refresh()->status);
    }

    public function testAsksThePurposeRoutingWithTheSourceSummaryAsJson(): void
    {
        $this->fakeCompletion($this->summaryJson('簡短摘要', '# 標題'));

        Config::setValue(Config::KEY_OPENROUTER_MODELS, [self::PURPOSE_KEY => 'openrouter/auto']);
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [
            self::PURPOSE_KEY => ['plugins' => [['id' => 'auto-router', 'cost_tier' => 'low']]],
        ]);

        (new SummaryTranslationJob($this->sourceSummary(), Summary::LOCALE_ZH_TW))->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();
            $content = $data['messages'][0]['content'];

            // 用途層決定模型與路由參數，兩者都要進到 request body。
            return $data['model'] === 'openrouter/auto'
                && $data['plugins'] === [['id' => 'auto-router', 'cost_tier' => 'low']]
                && str_contains($content, 'You are a professional translator.')
                && str_contains($content, '"short_summary":"the short one"')
                && str_contains($content, 'written exclusively in Traditional Chinese,');
        });
    }

    /**
     * 翻譯是全站共用的一列，沒有「當前使用者」可言——方案的 ai_routing 再怎麼設
     * 都不能蓋掉用途層（見 docs/lore/prompts/business-rules.md）。
     */
    public function testIgnoresPlanRoutingEvenWhenTheMediaHasOwners(): void
    {
        $this->fakeCompletion($this->summaryJson('簡短摘要', '# 標題'));

        Config::setValue(Config::KEY_OPENROUTER_MODELS, [self::PURPOSE_KEY => 'openrouter/auto']);

        $source = $this->sourceSummary();

        Plan::query()->update(['ai_routing' => ['model' => 'anthropic/claude-opus-5']]);

        (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->handle();

        Http::assertSent(fn ($request) => $request->data()['model'] === 'openrouter/auto');
    }

    public function testSendsTheSummaryUnescapedSoTheModelReadsItAsWritten(): void
    {
        $this->fakeCompletion($this->summaryJson('簡短摘要', '# 標題'));

        $source = $this->sourceSummary(Summary::LOCALE_ZH_TW);
        $source->fill(['text' => ['long_summary' => ['content' => '# 標題']]])->save();

        (new SummaryTranslationJob($source, Summary::LOCALE_EN))->handle();

        // json_encode would otherwise ship `標題`, which costs tokens
        // and gives the model a worse look at what it is translating.
        Http::assertSent(fn ($request) => str_contains($request->data()['messages'][0]['content'], '# 標題'));
    }

    public function testOverwritesAStaleTranslationForTheSameLocale(): void
    {
        $this->fakeCompletion($this->summaryJson('新的', '# 新標題'));

        $source = $this->sourceSummary();

        $stale = Summary::factory()->create([
            'media_id' => $source->media_id,
            'user_id'  => null,
            'locale'   => Summary::LOCALE_ZH_TW,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['short_summary' => '舊的', 'long_summary' => ['content' => '# 舊標題']],
        ]);

        (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->handle();

        $this->assertSame('# 新標題', $stale->refresh()->text['long_summary']['content']);
        $this->assertSame(2, Summary::query()->where('media_id', $source->media_id)->count());
    }

    /**
     * 使用者自己的摘要（user_id 有值）跟全站共用那筆是不同的資料列，翻譯只能
     * 動 user_id 為 null 的那一筆。
     */
    public function testDoesNotOverwriteAUsersOwnSummaryForTheTargetLocale(): void
    {
        $this->fakeCompletion($this->summaryJson('共用', '# 共用'));

        $source = $this->sourceSummary();
        $user = User::factory()->create();

        $mine = Summary::factory()->create([
            'media_id' => $source->media_id,
            'user_id'  => $user->id,
            'locale'   => Summary::LOCALE_ZH_TW,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['short_summary' => '我的', 'long_summary' => ['content' => '# 我的']],
        ]);

        (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->handle();

        $this->assertSame('# 我的', $mine->refresh()->text['long_summary']['content']);
        $this->assertSame(3, Summary::query()->where('media_id', $source->media_id)->count());
    }

    public function testAcceptsJsonThatArrivesWrappedInACodeFence(): void
    {
        $this->fakeCompletion("```json\n" . $this->summaryJson('簡短摘要', '# 圍起來的') . "\n```");

        $source = $this->sourceSummary();

        (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->handle();

        $translated = Summary::query()
            ->where('media_id', $source->media_id)
            ->where('locale', Summary::LOCALE_ZH_TW)
            ->first();

        $this->assertSame('# 圍起來的', $translated->text['long_summary']['content']);
    }

    public function testReleasesForRetryWhenTheReplyIsNotTheRequestedJson(): void
    {
        $this->fakeCompletion('# 只是 Markdown，沒有 JSON');

        $source = $this->sourceSummary();

        $job = new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle();

        $this->assertTrue($job->job->isReleased());
        $this->assertSame(180, $job->job->releaseDelay);

        $translated = Summary::query()
            ->where('media_id', $source->media_id)
            ->where('locale', Summary::LOCALE_ZH_TW)
            ->first();

        // Still in flight, not failed: reads only ask for completed summaries,
        // so the half-written row is invisible either way.
        $this->assertSame(Summary::STATUS_PROCESSING, $translated->status);
        $this->assertNull($translated->text);
    }

    public function testReleasesForRetryWhenTheRouterAnswersAnError(): void
    {
        // Completion does not throw on a non-2xx — a rate limit from the free
        // router arrives as a body with no `choices` at all.
        Http::fake([
            self::ENDPOINT => Http::response(['error' => ['code' => 429, 'message' => 'rate limited']], 429),
        ]);

        $job = new SummaryTranslationJob($this->sourceSummary(), Summary::LOCALE_ZH_TW);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle();

        $this->assertTrue($job->job->isReleased());
    }

    public function testMarksTheTranslationFailedOnceTheRetriesAreExhausted(): void
    {
        $this->fakeCompletion('# 只是 Markdown，沒有 JSON');

        $source = $this->sourceSummary();

        $job = new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW);
        $job->job = new FakeJob();
        $job->job->attempts = 3;

        $job->handle();

        $this->assertFalse($job->job->isReleased());

        $translated = Summary::query()
            ->where('media_id', $source->media_id)
            ->where('locale', Summary::LOCALE_ZH_TW)
            ->first();

        $this->assertSame(Summary::STATUS_FAILED, $translated->status);

        // The media keeps its summary either way: a missing translation is not
        // a failed summarisation.
        $this->assertSame(Media::STATUS_SUMMARIZED, $source->media->refresh()->status);
    }

    public function testDoesNothingWhenTheSourceHasNoTextLeft(): void
    {
        Http::fake();

        $source = $this->sourceSummary();
        $source->fill(['text' => null])->save();

        (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->handle();

        Http::assertNothingSent();
        $this->assertSame(1, Summary::query()->where('media_id', $source->media_id)->count());
    }

    public function testFailedHookSettlesTheTranslationWhenTheJobDiesOutsideHandle(): void
    {
        $source = $this->sourceSummary();

        $translated = Summary::factory()->create([
            'media_id' => $source->media_id,
            'user_id'  => null,
            'locale'   => Summary::LOCALE_ZH_TW,
            'status'   => Summary::STATUS_PROCESSING,
        ]);

        (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->failed(new RuntimeException('boom'));

        $this->assertSame(Summary::STATUS_FAILED, $translated->refresh()->status);
    }

    public function testTargetLocalesAreTheUiLocalesMinusTheSourceOne(): void
    {
        $this->assertSame(
            [Summary::LOCALE_ZH_TW, 'zh-CN'],
            SummaryTranslationJob::targetLocales(Summary::LOCALE_EN)
        );

        // zh-TW and zh-CN are one language but two summaries: a reader of one
        // does not want the other, so only the exact locale drops out.
        $this->assertSame(
            [Summary::LOCALE_EN, 'zh-CN'],
            SummaryTranslationJob::targetLocales(Summary::LOCALE_ZH_TW)
        );
    }

    public function testTargetLocalesNormalisesTheSourceLocaleBeforeComparing(): void
    {
        // 字幕與早期摘要存的是 `zh_tw`，跟 available_locales 的 `zh-TW` 字面不等。
        $this->assertSame(
            [Summary::LOCALE_EN, 'zh-CN'],
            SummaryTranslationJob::targetLocales('zh_tw')
        );
    }

    public function testUniqueIdIsScopedToTheSourceSummaryAndTheTargetLocale(): void
    {
        $source = $this->sourceSummary();

        $this->assertSame(
            $source->id . ':' . Summary::LOCALE_ZH_TW,
            (new SummaryTranslationJob($source, Summary::LOCALE_ZH_TW))->uniqueId()
        );
    }
}
