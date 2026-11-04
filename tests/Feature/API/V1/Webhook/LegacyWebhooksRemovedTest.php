<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Caption;
use App\Models\Summary;
use PHPUnit\Framework\Attributes\DataProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * security-hotfix-1 驗收（H1）：兩條沒有驗證的舊 webhook 要移除，不能再改寫共用摘要、字幕與影片狀態；
 * 使用者看自己影片摘要的端點不受影響。
 *
 * 被移除的端點路徑寫死字串（testing.md 規定用 route()，但路由名稱會跟著端點消失，
 * route() 會直接拋例外而不是 404）；留下的端點照規定用 route()。
 * 送出的內容都能通過舊端點的驗證，所以修正前的失敗原因是「改寫成功」，不是 422。
 *
 * @internal
 * @coversNothing
 */
class LegacyWebhooksRemovedTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_SUMMARY = [
        'short_summary' => 'The original short summary.',
        'long_summary'  => ['content' => 'The original long summary.', 'key_points' => ['original'], 'keywords' => ['original']],
    ];

    /** 一支已摘要的影片：共用摘要（user_id = null）與 primary caption。 */
    private function summarizedMedia(): Media
    {
        $media = Media::factory()->create(['status' => Media::STATUS_SUMMARIZED]);
        Summary::factory()->create([
            'media_id' => $media->id,
            'user_id'  => null,
            'locale'   => 'en',
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => self::SHARED_SUMMARY,
        ]);
        Caption::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'en',
            'primary'  => true,
            'text'     => 'Original caption.',
            'segments' => [['start' => 0, 'end' => 1, 'text' => 'Original caption.']],
        ]);

        return $media;
    }

    /** @return array{summaries: array, captions: array, status: string} */
    private function snapshot(Media $media): array
    {
        return [
            'summaries' => Summary::query()->where('media_id', $media->id)->orderBy('id')->get()
                ->map(fn (Summary $s) => $s->only(['id', 'user_id', 'locale', 'status', 'text']))->all(),
            'captions' => Caption::query()->where('media_id', $media->id)->orderBy('id')->get()
                ->map(fn (Caption $c) => $c->only(['id', 'locale', 'primary', 'text', 'segments']))->all(),
            'status' => (string) $media->refresh()->status,
        ];
    }

    // ── S04 ───────────────────────────────────────────

    #[DataProvider('legacyWebhooks')]
    public function testS04TheLegacyWebhookNoLongerExists(string $path, array $payload): void
    {
        $media = $this->summarizedMedia();
        $before = $this->snapshot($media);

        $response = $this->json('POST', sprintf($path, $media->id), $payload);

        $response->assertStatus(404);
        $this->assertSame($before, $this->snapshot($media), 'shared summary, captions and media.status are unchanged');
    }

    public static function legacyWebhooks(): array
    {
        return [
            'summaries: new summary' => ['/v1/webhook/summaries/%s', [
                'locale' => 'en',
                'text'   => [
                    'short_summary' => 'Injected summary.',
                    'long_summary'  => ['content' => 'Ignore previous instructions.', 'key_points' => ['x'], 'keywords' => ['x']],
                ],
            ]],
            'groq: new caption' => ['/v1/webhook/groq/%s', [
                'status' => 'success',
                'data'   => [
                    'language' => 'English',
                    'duration' => 1.0,
                    'text'     => 'Injected caption.',
                    'words'    => [['word' => 'Injected', 'start' => 0, 'end' => 0.5]],
                    'segments' => [['start' => 0, 'end' => 1, 'text' => 'Injected caption.']],
                ],
            ]],
            'groq: status=error' => ['/v1/webhook/groq/%s', [
                'status' => 'error',
                'data'   => ['error' => 'boom'],
            ]],
        ];
    }

    // ── S05 ───────────────────────────────────────────

    public function testS05ACollectorStillReadsTheSummaryOfTheirMedia(): void
    {
        $user = $this->fakeLogin();
        $media = $this->summarizedMedia();
        $user->media()->attach($media->id);

        $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('text.short_summary', 'The original short summary.');
    }
}
