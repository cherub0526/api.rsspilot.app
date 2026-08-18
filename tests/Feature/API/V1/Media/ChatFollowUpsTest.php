<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\Summary;
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

            public int $calls = 0;

            public function __construct(private array $questions)
            {
            }

            public function generate(string $answers): array
            {
                ++$this->calls;
                $this->received = $answers;

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
