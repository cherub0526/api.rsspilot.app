<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\CustomPrompt;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class CustomPromptsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePrompt(User $user, string $title = '學習筆記摘要'): CustomPrompt
    {
        return CustomPrompt::create([
            'user_id' => $user->getKey(),
            'title'   => $title,
            'content' => '請以學習筆記的風格整理這部影片的重點。',
        ]);
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->json('GET', route('api.v1.custom-prompts.index'))->assertStatus(401);
    }

    public function testIndexReturnsOnlyTheCurrentUserPrompts(): void
    {
        $user = $this->fakeLogin();
        $this->makePrompt($user);

        // 別人的設定不能出現在清單裡。
        $other = User::factory()->create();
        $this->makePrompt($other, '別人的設定');

        $response = $this->json('GET', route('api.v1.custom-prompts.index'));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '學習筆記摘要');
    }

    public function testIndexReturnsNewestFirst(): void
    {
        $user = $this->fakeLogin();
        $this->makePrompt($user, '先建立的');
        $this->makePrompt($user, '後建立的');

        $this->json('GET', route('api.v1.custom-prompts.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', '後建立的');
    }

    public function testStoreCreatesAPromptForTheCurrentUser(): void
    {
        $user = $this->fakeLogin();

        $response = $this->json('POST', route('api.v1.custom-prompts.store'), [
            'title'   => '商業分析摘要',
            'content' => '請以商業分析的角度摘要此影片。',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('title', '商業分析摘要');

        $prompt = CustomPrompt::query()->where('title', '商業分析摘要')->first();

        $this->assertNotNull($prompt);
        $this->assertSame($user->getKey(), $prompt->user_id);
    }

    public function testStoreRequiresAuthentication(): void
    {
        $this->json('POST', route('api.v1.custom-prompts.store'), [
            'title'   => 'x',
            'content' => 'y',
        ])->assertStatus(401);
    }

    public function testStoreRejectsMissingFields(): void
    {
        $this->fakeLogin();

        $this->json('POST', route('api.v1.custom-prompts.store'), [])->assertStatus(422);
    }

    public function testStoreRejectsTitleLongerThanTheColumn(): void
    {
        $this->fakeLogin();

        // title 是 varchar(255)：sqlite 不會擋，MySQL 會，所以規則必須自己擋。
        $this->json('POST', route('api.v1.custom-prompts.store'), [
            'title'   => str_repeat('a', 256),
            'content' => '內容',
        ])->assertStatus(422);
    }

    public function testShowReturnsTheOwnPrompt(): void
    {
        $user = $this->fakeLogin();
        $prompt = $this->makePrompt($user);

        $uri = route('api.v1.custom-prompts.show', ['promptId' => $prompt->getKey()]);

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonPath('title', '學習筆記摘要')
            ->assertJsonPath('content', '請以學習筆記的風格整理這部影片的重點。');
    }

    public function testShowDoesNotExposeSomeoneElsePrompt(): void
    {
        $this->fakeLogin();

        $other = User::factory()->create();
        $prompt = $this->makePrompt($other, '別人的設定');

        $uri = route('api.v1.custom-prompts.show', ['promptId' => $prompt->getKey()]);

        $this->json('GET', $uri)->assertStatus(404);
    }

    public function testShowRequiresAuthentication(): void
    {
        $user = User::factory()->create();
        $prompt = $this->makePrompt($user);

        $uri = route('api.v1.custom-prompts.show', ['promptId' => $prompt->getKey()]);

        $this->json('GET', $uri)->assertStatus(401);
    }

    public function testUpdateReplacesTheOwnPrompt(): void
    {
        $user = $this->fakeLogin();
        $prompt = $this->makePrompt($user);

        $uri = route('api.v1.custom-prompts.update', ['promptId' => $prompt->getKey()]);

        $this->json('PUT', $uri, [
            'title'   => '改過的標題',
            'content' => '改過的內容。',
        ])->assertStatus(200)->assertJsonPath('title', '改過的標題');

        $fresh = $prompt->fresh();

        $this->assertSame('改過的標題', $fresh->title);
        $this->assertSame('改過的內容。', $fresh->content);
    }

    public function testUpdateRejectsMissingFields(): void
    {
        $user = $this->fakeLogin();
        $prompt = $this->makePrompt($user);

        $uri = route('api.v1.custom-prompts.update', ['promptId' => $prompt->getKey()]);

        $this->json('PUT', $uri, [])->assertStatus(422);
    }

    public function testUpdateDoesNotTouchSomeoneElsePrompt(): void
    {
        $this->fakeLogin();

        $other = User::factory()->create();
        $prompt = $this->makePrompt($other, '別人的設定');

        $uri = route('api.v1.custom-prompts.update', ['promptId' => $prompt->getKey()]);

        $this->json('PUT', $uri, [
            'title'   => '被改掉了',
            'content' => '被改掉了。',
        ])->assertStatus(404);

        $this->assertSame('別人的設定', $prompt->fresh()->title);
    }

    public function testDestroyRemovesTheOwnPrompt(): void
    {
        $user = $this->fakeLogin();
        $prompt = $this->makePrompt($user);

        $uri = route('api.v1.custom-prompts.destroy', ['promptId' => $prompt->getKey()]);

        $this->json('DELETE', $uri)->assertStatus(200);

        $this->assertNull(CustomPrompt::query()->find($prompt->getKey()));
    }

    public function testDestroyDoesNotTouchSomeoneElsePrompt(): void
    {
        $this->fakeLogin();

        $other = User::factory()->create();
        $prompt = $this->makePrompt($other, '別人的設定');

        $uri = route('api.v1.custom-prompts.destroy', ['promptId' => $prompt->getKey()]);

        // 別人的 id 與不存在的 id 都回 404，不透露這筆資料存不存在。
        $this->json('DELETE', $uri)->assertStatus(404);

        $this->assertNotNull(CustomPrompt::query()->find($prompt->getKey()));
    }

    public function testDestroyReturnsNotFoundForAMissingPrompt(): void
    {
        $this->fakeLogin();

        $uri = route('api.v1.custom-prompts.destroy', ['promptId' => '01000000000000000000000000']);

        $this->json('DELETE', $uri)->assertStatus(404);
    }

    public function testDestroyRequiresAuthentication(): void
    {
        $user = User::factory()->create();
        $prompt = $this->makePrompt($user);

        $uri = route('api.v1.custom-prompts.destroy', ['promptId' => $prompt->getKey()]);

        $this->json('DELETE', $uri)->assertStatus(401);
    }
}
