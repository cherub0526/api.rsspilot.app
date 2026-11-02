<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VideoTranscriber;

use Tests\TestCase;
use App\Services\VideoTranscriber\Outcome;
use App\Services\VideoTranscriber\PollResult;
use Tests\Concerns\FakesVideoTranscriberOpenApi;
use App\Services\VideoTranscriber\OpenApiTranscriptionProvider;

/**
 * 驗收測試沒有涵蓋的上限與白名單（SR-2.3、SR-4.4、SR-4.7）。
 *
 * @internal
 * @coversNothing
 */
class OpenApiTranscriptionProviderTest extends TestCase
{
    use FakesVideoTranscriberOpenApi;

    private const STATUS_PATH = 'GET /transcriptions/' . self::TASK_ID;

    private const RESULT_PATH = 'GET /transcriptions/' . self::TASK_ID . '/result';

    private function poll(array|string $result): PollResult
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber([
            self::STATUS_PATH => $this->task('succeeded'),
            self::RESULT_PATH => $this->ok($result),
        ]);

        return (new OpenApiTranscriptionProvider())->poll(self::TASK_ID);
    }

    public function testAnOversizedResultFailsWithoutBeingKept(): void
    {
        $result = $this->resultBody([$this->segment(0, 0, 1, 'hi')]);
        $result['transcript']['text'] = str_repeat('a', OpenApiTranscriptionProvider::MAX_RESULT_BYTES);

        $poll = $this->poll($result);

        $this->assertSame(Outcome::Failed, $poll->outcome);
        $this->assertNull($poll->archive);
        $this->assertSame([], $poll->segments);
    }

    public function testSegmentsBeyondTheCapAreDroppedWithAWarning(): void
    {
        $this->recordLogs();
        $segments = [];
        for ($i = 0; $i < OpenApiTranscriptionProvider::MAX_SEGMENTS + 5; ++$i) {
            $segments[] = $this->segment($i, $i, $i + 1, "s{$i}");
        }

        $poll = $this->poll($this->resultBody($segments));

        $this->assertSame(Outcome::Ok, $poll->outcome);
        $this->assertCount(OpenApiTranscriptionProvider::MAX_SEGMENTS, $poll->segments);
        $this->assertNotEmpty($this->logsAt('warning'));
        $this->assertStringNotContainsString('s0', $this->allLogText(), 'the warning carries no caption text');
    }

    public function testTextBeyondTheCapIsTruncated(): void
    {
        $this->recordLogs();
        $long = str_repeat('b', 1_000_000);

        $poll = $this->poll($this->resultBody([
            $this->segment(0, 0, 1, $long),
            $this->segment(1, 1, 2, $long),
            $this->segment(2, 2, 3, $long),
        ]));

        $this->assertSame(Outcome::Ok, $poll->outcome);
        $this->assertCount(2, $poll->segments);
        $this->assertNotEmpty($this->logsAt('warning'));
    }

    public function testTheArchiveKeepsOnlyWhitelistedFields(): void
    {
        $result = $this->resultBody([$this->segment(0, 0, 1, 'hi')]);
        $result['account'] = ['email' => 'owner@example.test'];
        $result['usage'] = ['used_quotas' => 3];

        $poll = $this->poll($result);

        $this->assertSame(['id', 'status', 'result_expires_at', 'transcript', 'chapters', 'translation'], array_keys((array) $poll->archive));
        $this->assertSame('hi', $poll->archive['transcript']['segments'][0]['text'] ?? null);
    }

    /** pm 裁定（T02 r1）：Railway 上留了空變數時視同沒設定，不讓轉錄停擺。 */
    public function testAnEmptyProviderSettingMeansLegacy(): void
    {
        config()->set('services.videotranscriber.provider', '');

        $this->assertSame(OpenApiTranscriptionProvider::LEGACY, OpenApiTranscriptionProvider::configured());
        $this->assertTrue(OpenApiTranscriptionProvider::isKnown(OpenApiTranscriptionProvider::configured()));
    }
}
