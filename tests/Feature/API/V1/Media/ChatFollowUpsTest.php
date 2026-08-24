<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\Setting;
use App\Models\Summary;
use App\Models\ChatUsage;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\FollowUpQuestions\FollowUpQuestionsGeneratorInterface;

/**
 * GET /v1/media/{mediaId}/chat/sessions/{sessionId}/follow-ups.
 *
 * @internal
 * @coversNothing
 */
class ChatFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 記下收到的 answers，供斷言「餵進去的是最後一則 AI 回應」。
     */
    private function fakeGenerator(array $questions = ['問題一', '問題二', '問題三']): object
    {
        $generator = new class($questions) implements FollowUpQuestionsGeneratorInterface {
            public ?string $received = null;

            public ?string $receivedLanguage = null;

            public int $calls = 0;

            public function __construct(private array $questions)
            {
            }

            public function generate(string $answers, string $language): array
            {
                ++$this->calls;
                $this->received = $answers;
                $this->receivedLanguage = $language;

                return $this->questions;
            }
        };

        $this->app->instance(FollowUpQuestionsGeneratorInterface::class, $generator);

        return $generator;
    }

    private function createSession(User $user, Media $media): ChatSession
    {
        return ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
            'title'    => 'Session',
        ]);
    }

    private function addMessage(ChatSession $session, string $role, string $content, ?string $createdAt = null): void
    {
        ChatMessage::create([
            'session_id' => $session->id,
            'role'       => $role,
            'content'    => $content,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function freeMedia(): Media
    {
        $source = Source::factory()->create(['free' => true]);

        return Media::factory()->create(['source_id' => $source->id]);
    }

    private function addSummary(
        Media $media,
        string $content,
        string $status = Summary::STATUS_COMPLETED,
        ?string $createdAt = null
    ): void {
        Summary::create([
            'media_id'   => $media->id,
            'locale'     => Summary::LOCALE_ZH_TW,
            'status'     => $status,
            'text'       => ['long_summary' => ['content' => $content]],
            'created_at' => $createdAt ?? now(),
        ]);
    }

    /**
     * 沒有訂閱的使用者吃的是「月費 0 元」的方案，額度就從這裡來。
     */
    private function createFreePlan(int $chatLimit): Plan
    {
        $plan = Plan::factory()->create([
            'title'      => 'Free',
            'chat_limit' => $chatLimit,
            'status'     => Plan::STATUS_ACTIVE,
        ]);

        Price::create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 0,
        ]);

        return $plan;
    }

    private function useQuota(User $user, int $count): void
    {
        ChatUsage::create([
            'user_id'    => $user->id,
            'quota_date' => now(config('ai.chat.quota_timezone') ?: config('app.timezone'))->toDateString(),
            'count'      => $count,
        ]);
    }

    private function fetch(Media $media, string $sessionId)
    {
        return $this->json('GET', route('api.v1.media.chat.sessions.follow-ups', [
            'mediaId'   => $media->id,
            'sessionId' => $sessionId,
        ]));
    }

    // ================================================================

    public function testRequiresAuth(): void
    {
        $media = $this->freeMedia();
        $user = User::factory()->create();
        $session = $this->createSession($user, $media);

        $this->fetch($media, $session->id)->assertStatus(401);
    }

    public function testReturns404WhenSessionNotOwnedByUser(): void
    {
        $this->fakeLogin();
        $other = User::factory()->create();
        $media = $this->freeMedia();
        $session = $this->createSession($other, $media);

        $this->fetch($media, $session->id)->assertStatus(404);
    }

    public function testReturns404WhenMediaNotAccessible(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = $this->createSession($user, $media);

        $this->fetch($media, $session->id)->assertStatus(404);
    }

    /**
     * 素材是這個 session 最後一則 AI 回應，不是使用者的提問、也不是較早的回應。
     */
    public function testGeneratesFromTheLatestAiAnswer(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_USER, '第一個提問', '2026-01-01 00:00:00');
        $this->addMessage($session, ChatMessage::ROLE_AI, '較早的回應', '2026-01-01 00:00:01');
        $this->addMessage($session, ChatMessage::ROLE_USER, '第二個提問', '2026-01-01 00:00:02');
        $this->addMessage($session, ChatMessage::ROLE_AI, '最新的回應', '2026-01-01 00:00:03');

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)
            ->assertStatus(200)
            ->assertJson(['data' => ['問題一', '問題二', '問題三']]);

        $this->assertSame('最新的回應', $generator->received);
    }

    public function testPassesTheUsersAiLanguageToTheGenerator(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);
        $this->addMessage($session, ChatMessage::ROLE_AI, '回應');

        Setting::create([
            'user_id' => $user->id,
            'data'    => ['ai' => ['language' => 'zh-TW']],
        ]);

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)->assertStatus(200);

        $this->assertSame('Traditional Chinese', $generator->receivedLanguage);
    }

    /**
     * 沒有設定時退回 User::DEFAULT_AI_LANGUAGE，而不是空字串——空字串會讓
     * 提示詞變成 "Write the questions ONLY in ."，模型行為無從預期。
     */
    public function testFallsBackToTheDefaultLanguageWhenUserHasNoSetting(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);
        $this->addMessage($session, ChatMessage::ROLE_AI, '回應');

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)->assertStatus(200);

        $this->assertSame('English', $generator->receivedLanguage);
    }

    /**
     * 退回摘要當素材時語言仍取使用者設定。摘要的語言由 videotranscriber.ai
     * 決定（這裡刻意造一份 zh-TW 摘要），比對素材會產出使用者讀不懂的問題。
     */
    public function testUsesTheUsersLanguageEvenWhenFallingBackToTheSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);
        $this->addSummary($media, '這是中文摘要');

        Setting::create([
            'user_id' => $user->id,
            'data'    => ['ai' => ['language' => 'ja']],
        ]);

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)->assertStatus(200);

        $this->assertSame('這是中文摘要', $generator->received);
        $this->assertSame('Japanese', $generator->receivedLanguage);
    }

    /**
     * session 還沒有任何 AI 回應 → 退回影片摘要的 long_summary 當素材。
     */
    public function testFallsBackToTheMediaSummaryWhenSessionHasNoAiAnswer(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_USER, '只有提問');
        $this->addSummary($media, '# 影片摘要');

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)
            ->assertStatus(200)
            ->assertJson(['data' => ['問題一', '問題二', '問題三']]);

        $this->assertSame('# 影片摘要', $generator->received);
    }

    /**
     * 有 AI 回應時就用回應，不會被摘要蓋掉。
     */
    public function testPrefersTheAiAnswerOverTheMediaSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_AI, '最新的回應');
        $this->addSummary($media, '# 影片摘要');

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)->assertStatus(200);

        $this->assertSame('最新的回應', $generator->received);
    }

    /**
     * 重跑摘要時會先建一筆 text 還空著的資料列，不能被它蓋掉先前可用的那份。
     */
    public function testFallbackIgnoresSummariesThatAreNotCompleted(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addSummary($media, '# 已完成的摘要', Summary::STATUS_COMPLETED, '2026-01-01 00:00:00');
        $this->addSummary($media, '', Summary::STATUS_CREATED, '2026-01-01 00:00:01');

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)->assertStatus(200);

        $this->assertSame('# 已完成的摘要', $generator->received);
    }

    /**
     * 沒有 AI 回應、也沒有可用摘要 → 回空陣列，而不是 404，也不該白呼叫推論。
     */
    public function testReturnsEmptyArrayWhenThereIsNoAiAnswerNorSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_USER, '只有提問');

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)
            ->assertStatus(200)
            ->assertJson(['data' => []]);

        $this->assertSame(0, $generator->calls);
    }

    /**
     * 當日額度用完 → 不產生。問題產出來使用者也送不出去，白燒一次推論。
     */
    public function testReturnsEmptyArrayWhenDailyChatQuotaIsUsedUp(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(3);
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_AI, '回應內容');
        $this->useQuota($user, 3);

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)
            ->assertStatus(200)
            ->assertJson(['data' => []]);

        $this->assertSame(0, $generator->calls);
    }

    /**
     * 還有剩餘額度就照常產生，而且不會因此扣掉額度。
     */
    public function testGeneratesWhenQuotaRemainsAndDoesNotConsumeIt(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(3);
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_AI, '回應內容');
        $this->useQuota($user, 2);

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)
            ->assertStatus(200)
            ->assertJson(['data' => ['問題一', '問題二', '問題三']]);

        $this->assertSame(1, $generator->calls);
        $this->assertSame(2, (int) ChatUsage::where('user_id', $user->id)->value('count'));
    }

    /**
     * chat_limit = 0 是不限制，不能被當成「一次都不能用」。
     */
    public function testGeneratesWhenPlanHasUnlimitedChatQuota(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(0);
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_AI, '回應內容');
        $this->useQuota($user, 99);

        $generator = $this->fakeGenerator();

        $this->fetch($media, $session->id)->assertStatus(200);

        $this->assertSame(1, $generator->calls);
    }

    /**
     * 模型沒照格式回應時 parser 會回空陣列——端點照樣是 200，由前端決定不顯示。
     */
    public function testReturnsEmptyArrayWhenModelIgnoresTheFormat(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_AI, '回應內容');

        $this->fakeGenerator([]);

        $this->fetch($media, $session->id)
            ->assertStatus(200)
            ->assertJson(['data' => []]);
    }

    /**
     * 不計入每日 chat 額度：呼叫前後 chat_usages 都不該有任何資料列。
     */
    public function testDoesNotConsumeDailyChatQuota(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->freeMedia();
        $session = $this->createSession($user, $media);

        $this->addMessage($session, ChatMessage::ROLE_AI, '回應內容');
        $this->fakeGenerator();

        $this->fetch($media, $session->id)->assertStatus(200);

        $this->assertDatabaseCount('chat_usages', 0);
    }
}
