<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Models\Media;
use App\Models\Caption;
use Hypervel\Queue\Queueable;
use App\Services\YoutubeService;
use Hypervel\Support\Facades\Http;
use Hypervel\Queue\Contracts\ShouldQueue;

/**
 * Fetches official YouTube captions for a video via YouTube Data API v3,
 * then stores each language track in the captions table.
 *
 * Flow:
 *   1. captions.list (official API v3) → discover available tracks + language codes
 *   2. timedtext endpoint              → download content (captions.download requires
 *                                        OAuth; timedtext gives identical data for
 *                                        public videos without auth)
 *   3. Caption::updateOrCreate         → persist per locale
 */
class YoutubeDataCaptionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Media $media)
    {
        $this->queue = 'media.youtube-data-caption';
    }

    public function handle(YoutubeService $youtube): void
    {
        $videoId = $this->extractVideoId();

        if (!$videoId) {
            return;
        }

        // Step 1: List available caption tracks via YouTube Data API v3
        $tracks = $youtube->getVideoCaptions($videoId);

        if (empty($tracks)) {
            return;
        }

        $this->media->fill(['status' => Media::STATUS_TRANSCRIBING])->save();

        $saved = 0;

        foreach ($tracks as $track) {
            $snippet = $track->getSnippet();
            $langCode = (string) ($snippet->getLanguage() ?? '');
            $trackKind = strtolower((string) ($snippet->getTrackKind() ?? 'standard'));

            if ($langCode === '') {
                continue;
            }

            // Step 2: Download caption content via timedtext endpoint
            [$text, $segments] = $this->downloadAndParse($videoId, $langCode, $trackKind);

            if ($text === '') {
                continue;
            }

            // Step 3: Persist to captions table
            Caption::updateOrCreate(
                [
                    'media_id' => $this->media->id,
                    'locale'   => $this->mapLocale($langCode),
                ],
                [
                    'primary'       => $trackKind === 'standard',
                    'text'          => $text,
                    'segments'      => $segments,
                    'word_segments' => [],
                ]
            );

            ++$saved;
        }

        if ($saved > 0) {
            $this->media->fill(['status' => Media::STATUS_TRANSCRIBED])->save();
        }
    }

    /**
     * Downloads caption content from YouTube's timedtext endpoint and parses it.
     *
     * The timedtext API returns JSON3 format:
     * {"events": [{"tStartMs": 0, "dDurationMs": 2000, "segs": [{"utf8": "Hello"}]}]}
     *
     * @return array{string, list<array{start: float, end: float, text: string}>}
     */
    private function downloadAndParse(string $videoId, string $langCode, string $trackKind): array
    {
        $query = array_filter([
            'v'    => $videoId,
            'lang' => $langCode,
            'fmt'  => 'json3',
            'kind' => $trackKind === 'asr' ? 'asr' : null,
        ]);

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ])->get('https://www.youtube.com/api/timedtext', $query);

        if (!$response->successful()) {
            return ['', []];
        }

        $events = $response->json('events') ?? [];

        return $this->parseEvents($events);
    }

    /**
     * Parses a JSON3 events array into plain text and a segments list.
     *
     * @param list<array<string, mixed>> $events
     * @return array{string, list<array{start: float, end: float, text: string}>}
     */
    private function parseEvents(array $events): array
    {
        $textParts = [];
        $segments = [];

        foreach ($events as $event) {
            if (empty($event['segs'])) {
                continue;
            }

            $startMs = (int) ($event['tStartMs'] ?? 0);
            $durationMs = (int) ($event['dDurationMs'] ?? 0);

            $content = trim(
                implode(
                    '',
                    array_map(
                        static fn (array $seg) => $seg['utf8'] ?? '',
                        $event['segs']
                    )
                )
            );

            if ($content === '') {
                continue;
            }

            $textParts[] = $content;
            $segments[] = [
                'start' => round($startMs / 1000, 3),
                'end'   => round(($startMs + $durationMs) / 1000, 3),
                'text'  => $content,
            ];
        }

        return [implode(' ', $textParts), $segments];
    }

    /**
     * Extracts the bare YouTube video ID from the media record.
     *
     * Tries video_detail['yt:videoId'] first, then parses resource_id
     * which is stored in "yt:video:{videoId}" format from the RSS feed.
     */
    private function extractVideoId(): ?string
    {
        $videoId = $this->media->video_detail['yt:videoId'] ?? null;

        if (!empty($videoId)) {
            return (string) $videoId;
        }

        if (preg_match('/^yt:video:(.+)$/', (string) ($this->media->resource_id ?? ''), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Maps a YouTube BCP-47 language code to the app's Caption locale constants.
     *
     * Falls through to the raw language code for unrecognised languages so
     * that novel tracks are still persisted rather than discarded.
     */
    private function mapLocale(string $langCode): string
    {
        return match (true) {
            str_starts_with($langCode, 'zh') => Caption::LOCAL_ZH_TW,
            str_starts_with($langCode, 'en') => Caption::LOCAL_EN,
            default                          => $langCode,
        };
    }
}
