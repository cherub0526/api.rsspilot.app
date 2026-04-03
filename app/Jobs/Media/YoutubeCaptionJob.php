<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Models\Caption;
use App\Models\Media;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Queueable;
use Hypervel\Support\Facades\Http;

class YoutubeCaptionJob implements ShouldQueue
{
    use Queueable;

    protected Media $media;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media)
    {
        $this->media = $media;

        $this->queue = 'media.caption';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $videoId = $this->media->video_detail['yt:videoId'];

        $captionTracks = $this->fetchCaptionTracks($videoId);

        if (empty($captionTracks)) {
            return;
        }

        $this->media->fill(['status' => Media::STATUS_TRANSCRIBING])->save();

        foreach ($captionTracks as $track) {
            $langCode  = $track['languageCode'] ?? '';
            $baseUrl   = $track['baseUrl'] ?? '';
            $isDefault = ($track['vssId'] ?? '') === 'a.' . $langCode; // auto-generated tracks have vssId prefix "a."

            if (empty($baseUrl)) {
                continue;
            }

            $captionData = $this->fetchCaptionTrack($baseUrl);

            if (empty($captionData)) {
                continue;
            }

            [$text, $segments] = $this->parseEvents($captionData['events'] ?? []);

            Caption::updateOrCreate(
                [
                    'media_id' => $this->media->id,
                    'locale'   => $this->mapLocale($langCode),
                ],
                [
                    'primary'       => $isDefault,
                    'text'          => $text,
                    'segments'      => $segments,
                    'word_segments' => [],
                ]
            );
        }

        $this->media->fill(['status' => Media::STATUS_TRANSCRIBED])->save();
    }

    /**
     * Fetch available caption tracks via YouTube Innertube API.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchCaptionTracks(string $videoId): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ])->post('https://www.youtube.com/youtubei/v1/player?prettyPrint=false', [
            'context' => [
                'client' => [
                    'clientName'    => 'WEB',
                    'clientVersion' => '2.20240101.00.00',
                ],
            ],
            'videoId' => $videoId,
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('captions.playerCaptionsTracklistRenderer.captionTracks') ?? [];
    }

    /**
     * Fetch caption content from a track baseUrl (fmt=json3).
     *
     * @return array<string, mixed>
     */
    private function fetchCaptionTrack(string $baseUrl): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ])->get($baseUrl . '&fmt=json3');

        if (!$response->successful()) {
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Parse JSON3 events into plain text and segments array.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array{string, array<int, array<string, mixed>>}
     */
    private function parseEvents(array $events): array
    {
        $text     = '';
        $segments = [];

        foreach ($events as $event) {
            if (!isset($event['segs'])) {
                continue;
            }

            $startMs    = (int) ($event['tStartMs'] ?? 0);
            $durationMs = (int) ($event['dDurationMs'] ?? 0);
            $content    = implode('', array_column($event['segs'], 'utf8'));
            $content    = trim($content);

            if ($content === '') {
                continue;
            }

            $text      .= $content . ' ';
            $segments[] = [
                'start' => round($startMs / 1000, 3),
                'end'   => round(($startMs + $durationMs) / 1000, 3),
                'text'  => $content,
            ];
        }

        return [trim($text), $segments];
    }

    private function mapLocale(string $langCode): string
    {
        return match (true) {
            str_starts_with($langCode, 'zh') => Caption::LOCAL_ZH_TW,
            str_starts_with($langCode, 'en') => Caption::LOCAL_EN,
            default                          => $langCode,
        };
    }
}
