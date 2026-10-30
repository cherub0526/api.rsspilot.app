<?php

declare(strict_types=1);

namespace Tests\Feature\API\Mcp;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\Caption;
use App\Models\Summary;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * MCP 端點：使用者把 RSSPilot 接到自己的 AI 工具上的那條路。
 *
 * @internal
 * @coversNothing
 */
class ServerControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建一個當下生效的方案。沒有訂閱的人吃的是「月費 0 元」的那個方案。
     */
    private function createPlan(bool $mcpEnabled = true): Plan
    {
        return Plan::withoutEvents(function () use ($mcpEnabled) {
            $plan = Plan::factory()->create([
                'title'       => $mcpEnabled ? 'Pro' : 'Free',
                'mcp_enabled' => $mcpEnabled,
                'status'      => Plan::STATUS_ACTIVE,
            ]);

            Price::create(['plan_id' => $plan->id, 'unit' => Price::UNIT_MONTHLY, 'price' => 0]);

            return $plan;
        });
    }

    /** 帶著某個使用者的 API key 發一個 JSON-RPC 請求。 */
    private function rpc(?User $user, string $method, array $params = [], mixed $id = 1)
    {
        $body = array_filter([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => $method,
            'params'  => $params ?: null,
        ], fn ($v): bool => $v !== null);

        $headers = $user
            ? ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken]
            : [];

        return $this->withHeaders($headers)->json('POST', '/mcp', $body);
    }

    /** 一支屬於這位使用者的影片，附一份完成的摘要。 */
    private function videoFor(User $user, string $title = '測試影片'): Media
    {
        $source = Source::factory()->create(['title' => '測試頻道']);
        $media = Media::factory()->create(['source_id' => $source->id, 'title' => $title]);

        $user->sources()->attach($source->id);
        $user->media()->attach($media->id);

        Summary::create([
            'media_id' => $media->id,
            'user_id'  => null,
            'locale'   => 'en',
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => [
                'short_summary' => '一句話摘要',
                'long_summary'  => [
                    'content'    => "開場白。\n\n### 第一節\n第一節的內容。\n\n### 第二節\n第二節的內容。",
                    'key_points' => ['重點一'],
                    'keywords'   => ['關鍵字'],
                ],
            ],
        ]);

        return $media;
    }

    /** 幫某支影片加一份帶時間軸的逐字稿。 */
    private function captionFor(Media $media): Caption
    {
        return Caption::create([
            'media_id' => $media->id,
            'locale'   => 'zh-TW',
            'primary'  => true,
            'text'     => '第一句。第二句。第三句。',
            'segments' => [
                ['id' => 0, 'start' => 0, 'end' => 5, 'text' => '第一句。', 'tokens' => [1, 2]],
                ['id' => 1, 'start' => 5, 'end' => 10, 'text' => '第二句。', 'tokens' => [3, 4]],
                ['id' => 2, 'start' => 10, 'end' => 15, 'text' => '第三句。', 'tokens' => [5, 6]],
            ],
        ]);
    }

    // ================================================================

    /**
     * MCP 是 Pro 以上的功能，免費方案帶著有效的金鑰也讀不到資料。
     *
     * **每一次請求都檢查**，不是只在產生金鑰時檢查：金鑰不會過期，但方案會。
     */
    public function testFreePlansAreTurnedAwayEvenWithAValidKey(): void
    {
        $this->createPlan(mcpEnabled: false);
        $user = User::factory()->create();

        $this->rpc($user, 'tools/list')
            ->assertStatus(403)
            ->assertJsonPath('error.code', -32001);
    }

    /** 沒有方案時一併擋下——無從判斷權益的預設是不給。 */
    public function testUsersWithoutAPlanAreTurnedAway(): void
    {
        $user = User::factory()->create();

        $this->rpc($user, 'tools/list')->assertStatus(403);
    }

    /** 沒有 key 就進不來——這個端點上的每一筆資料都是某個人的。 */
    public function testRequiresAnApiKey(): void
    {
        $this->rpc(null, 'tools/list')->assertStatus(401);
    }

    public function testListsTheAvailableTools(): void
    {
        $this->createPlan();
        $user = User::factory()->create();

        $response = $this->rpc($user, 'tools/list')->assertStatus(200);

        $names = array_column($response->json('result.tools'), 'name');

        $this->assertEqualsCanonicalizing(
            [
                'list_sources',
                'list_videos',
                'search_videos',
                'get_summary',
                'get_summary_outline',
                'get_transcript',
            ],
            $names
        );
    }

    /**
     * 舊世代的客戶端會先握手。回它要求的版本（我們支援的話），不然它會直接斷線。
     */
    public function testInitializeEchoesASupportedProtocolVersion(): void
    {
        $this->createPlan();
        $user = User::factory()->create();

        $this->rpc($user, 'initialize', ['protocolVersion' => '2025-06-18'])
            ->assertStatus(200)
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'rsspilot');

        // 沒聽過的版本回我們最新的那一版
        $this->rpc($user, 'initialize', ['protocolVersion' => '1999-01-01'])
            ->assertStatus(200)
            ->assertJsonPath('result.protocolVersion', '2026-07-28');
    }

    public function testCallsAToolAndReturnsTextContent(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $this->videoFor($user, '關於快取的影片');

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'list_videos',
            'arguments' => [],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertCount(1, $payload['videos']);
        $this->assertSame('關於快取的影片', $payload['videos'][0]['title']);
        $this->assertSame('測試頻道', $payload['videos'][0]['source']);
    }

    public function testGetSummaryReturnsTheSummaryBody(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_summary',
            'arguments' => ['media_id' => $media->id],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame('一句話摘要', $payload['summary']['short']);
        $this->assertStringContainsString('第一節的內容。', $payload['summary']['content']);
        $this->assertSame(['重點一'], $payload['summary']['key_points']);
    }

    /**
     * 影片網址要能真的打得開。
     *
     * RSS 收進來的 resource_id 是 `yt:video:XXXX`，直接串進 watch?v= 會組出一個
     * 打不開的網址——實測資料庫裡 196 筆全是這個格式。
     */
    public function testVideoUrlUsesTheRealYoutubeId(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $source = Source::factory()->create();
        $media = Media::factory()->create([
            'source_id'    => $source->id,
            'resource_id'  => 'yt:video:cZN2SPShBgI',
            'video_detail' => ['yt:videoId' => 'cZN2SPShBgI'],
        ]);
        $user->media()->attach($media->id);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'list_videos',
            'arguments' => [],
        ])->assertStatus(200);

        $videos = json_decode($response->json('result.content.0.text'), true)['videos'];

        $this->assertSame('https://www.youtube.com/watch?v=cZN2SPShBgI', $videos[0]['url']);
    }

    /** 沒有 video_detail 的舊資料，退而求其次剝掉前綴。 */
    public function testVideoUrlFallsBackToStrippingThePrefix(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $source = Source::factory()->create();
        $media = Media::factory()->create([
            'source_id'    => $source->id,
            'resource_id'  => 'yt:video:abc123',
            'video_detail' => null,
        ]);
        $user->media()->attach($media->id);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'list_videos',
            'arguments' => [],
        ])->assertStatus(200);

        $videos = json_decode($response->json('result.content.0.text'), true)['videos'];

        $this->assertSame('https://www.youtube.com/watch?v=abc123', $videos[0]['url']);
    }

    /** 大綱只給標題與預覽，讓模型先挑再讀。 */
    public function testSummaryOutlineListsTheSections(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_summary_outline',
            'arguments' => ['media_id' => $media->id],
        ])->assertStatus(200);

        $sections = json_decode($response->json('result.content.0.text'), true)['sections'];

        $this->assertSame([null, '第一節', '第二節'], array_column($sections, 'heading'));
        $this->assertSame('開場白。', $sections[0]['preview'], '標題前的開場白也要算一節');
    }

    /** 指定章節時只回那一節，長摘要不必整份塞進 context。 */
    public function testGetSummaryCanReturnASingleSection(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_summary',
            'arguments' => ['media_id' => $media->id, 'section' => 2],
        ])->assertStatus(200);

        $section = json_decode($response->json('result.content.0.text'), true)['section'];

        $this->assertSame('第二節', $section['heading']);
        $this->assertSame('第二節的內容。', $section['content']);
        $this->assertSame(3, $section['of']);
    }

    public function testGetSummaryRejectsAnOutOfRangeSection(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_summary',
            'arguments' => ['media_id' => $media->id, 'section' => 99],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame('Section not found', $payload['error']);
        $this->assertSame(3, $payload['available']);
    }

    /**
     * 逐字稿預設回帶時間軸的片段，而且**只留 start / end / text**——原始資料還有
     * whisper 的 tokens 與 logprob，那些佔掉大量 context 卻沒有用。
     */
    public function testTranscriptReturnsTimestampedSegments(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);
        $this->captionFor($media);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_transcript',
            'arguments' => ['media_id' => $media->id],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame('zh-TW', $payload['locale']);
        $this->assertSame(3, $payload['total_segments']);
        $this->assertSame(['start', 'end', 'text'], array_keys($payload['segments'][0]));
        $this->assertSame('第一句。', $payload['segments'][0]['text']);
    }

    /** 時間區間讓模型能只讀它要的那一段。 */
    public function testTranscriptCanBeNarrowedByTime(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);
        $this->captionFor($media);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_transcript',
            'arguments' => ['media_id' => $media->id, 'start_second' => 6],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame(['第二句。', '第三句。'], array_column($payload['segments'], 'text'));
    }

    /** 還有下一頁時要給續讀的起點，模型不必自己算。 */
    public function testTranscriptPagesWithANextStart(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);
        $this->captionFor($media);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_transcript',
            'arguments' => ['media_id' => $media->id, 'limit' => 2],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame(2, $payload['returned']);
        // json 會把 10.0 送成 10，比對值而不是型別。
        $this->assertEquals(10, $payload['next_start_second']);
    }

    public function testTranscriptCanReturnPlainText(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);
        $this->captionFor($media);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_transcript',
            'arguments' => ['media_id' => $media->id, 'format' => 'text'],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame('第一句。第二句。第三句。', $payload['text']);
        $this->assertFalse($payload['truncated']);
    }

    /** 沒有逐字稿時講清楚，不要回一個空陣列讓模型以為影片沒有內容。 */
    public function testTranscriptSaysSoWhenThereIsNone(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $media = $this->videoFor($user);

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'get_transcript',
            'arguments' => ['media_id' => $media->id],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertNull($payload['transcript']);
        $this->assertSame('No transcript yet.', $payload['note']);
    }

    /** 新工具一樣只讀得到自己的資料。 */
    public function testTranscriptIsScopedToTheOwner(): void
    {
        $this->createPlan();
        $this->createPlan();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $media = $this->videoFor($stranger);
        $this->captionFor($media);

        $response = $this->rpc($owner, 'tools/call', [
            'name'      => 'get_transcript',
            'arguments' => ['media_id' => $media->id],
        ])->assertStatus(200);

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame('Video not found in this account', $payload['error']);
    }

    /**
     * **一把 key 只讀得到自己帳號的資料。**.
     *
     * 這是整個端點最重要的一條：所有查詢都從持有者的關聯出發，不是先查資料再
     * 比對持有者。
     */
    public function testAKeyOnlySeesItsOwnersData(): void
    {
        $this->createPlan();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $media = $this->videoFor($stranger, '別人的影片');

        $response = $this->rpc($owner, 'tools/call', [
            'name'      => 'list_videos',
            'arguments' => [],
        ])->assertStatus(200);

        $this->assertSame([], json_decode($response->json('result.content.0.text'), true)['videos']);

        // 就算直接指定 id 也讀不到
        $summary = $this->rpc($owner, 'tools/call', [
            'name'      => 'get_summary',
            'arguments' => ['media_id' => $media->id],
        ])->assertStatus(200);

        $payload = json_decode($summary->json('result.content.0.text'), true);
        $this->assertSame('Video not found in this account', $payload['error']);
    }

    public function testSearchMatchesTheTitle(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $this->videoFor($user, '關於快取的影片');
        $this->videoFor($user, '完全不相干');

        $response = $this->rpc($user, 'tools/call', [
            'name'      => 'search_videos',
            'arguments' => ['query' => '快取'],
        ])->assertStatus(200);

        $videos = json_decode($response->json('result.content.0.text'), true)['videos'];

        $this->assertCount(1, $videos);
        $this->assertSame('關於快取的影片', $videos[0]['title']);
    }

    /** 不認得的方法回 JSON-RPC 的 -32601，HTTP 是 404（規格要求）。 */
    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $this->createPlan();
        $user = User::factory()->create();

        $this->rpc($user, 'resources/list')
            ->assertStatus(404)
            ->assertJsonPath('error.code', -32601);
    }

    /** notification（沒有 id）不回結果，只回 202。 */
    public function testNotificationsGetAcceptedWithoutAResult(): void
    {
        $this->createPlan();
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->json('POST', '/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $response->assertStatus(202);
    }

    /**
     * 標頭與 body 對不起來就拒絕：中介層照標頭路由、伺服器照 body 執行，
     * 兩邊不一致是一個可以被利用的縫。
     */
    public function testRejectsAHeaderThatDoesNotMatchTheBody(): void
    {
        $this->createPlan();
        $user = User::factory()->create();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
            'Mcp-Method'    => 'tools/list',
        ])->json('POST', '/mcp', [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'list_videos'],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32020);
    }

    /** 沒實作的協定版本要明講支援哪些，客戶端才知道要降到哪一版。 */
    public function testRejectsAnUnsupportedProtocolVersion(): void
    {
        $this->createPlan();
        $user = User::factory()->create();

        $this->withHeaders([
            'Authorization'        => 'Bearer ' . $user->createToken('test')->plainTextToken,
            'MCP-Protocol-Version' => '1999-01-01',
        ])->json('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertStatus(400)
            ->assertJsonPath('error.data.supported.0', '2026-07-28');
    }

    /** 刪掉的 key 當場失效。 */
    public function testARevokedKeyStopsWorking(): void
    {
        $this->createPlan();
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->json('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertStatus(200);

        $user->tokens()->delete();

        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->json('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertStatus(401);
    }
}
