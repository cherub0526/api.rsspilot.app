<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Media;
use App\Models\User;
use Carbon\Carbon;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Mail\Mailable;
use Hypervel\Queue\Queueable;
use Hypervel\Queue\SerializesModels;

class DailyDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param Collection<int, Media> $videos Media models with loaded `summary` and `source` relations
     */
    public function __construct(
        public readonly User $user,
        public readonly Collection $videos,
    ) {
    }

    public function build(): self
    {
        $clientUrl = rtrim((string) env('CLIENT_URL', ''), '/');

        return $this->subject(__('mails.daily_digest.subject', ['count' => $this->videos->count()]))
            ->view('emails.daily-digest', [
                'userName'        => (string) $this->user->getAttribute('name'),
                'date'            => Carbon::now()->locale('zh-TW')->isoFormat('YYYY 年 M 月 D 日，dddd'),
                'videoCount'      => $this->videos->count(),
                'videos'          => $this->buildVideoList(),
                'channelCount'    => $this->user->rss()->count(),
                'totalMediaCount' => $this->user->media()->count(),
                'dashboardUrl'    => $clientUrl . '/dashboard',
                'pricingUrl'      => $clientUrl . '/pricing',
                'termsUrl'        => $clientUrl . '/terms',
                'privacyUrl'      => $clientUrl . '/privacy',
                'unsubscribeUrl'  => $clientUrl . '/settings/notifications',
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildVideoList(): array
    {
        $gradients = [
            'linear-gradient(135deg, #1e3a5f 0%, #2563eb 50%, #7c3aed 100%)',
            'linear-gradient(135deg, #064e3b 0%, #059669 50%, #34d399 100%)',
            'linear-gradient(135deg, #7c2d12 0%, #ea580c 50%, #fb923c 100%)',
            'linear-gradient(135deg, #312e81 0%, #6d28d9 50%, #a78bfa 100%)',
            'linear-gradient(135deg, #1e1b4b 0%, #4338ca 50%, #818cf8 100%)',
            'linear-gradient(135deg, #451a03 0%, #b45309 50%, #fcd34d 100%)',
        ];

        $emojis = ['🤖', '📊', '📱', '🧠', '🎯', '💡', '🚀', '📚'];

        return $this->videos->values()->map(function (Media $media, int $index) use ($gradients, $emojis): array {
            /** @var \App\Models\Summary|null $summary */
            $summary     = $media->summary()->first();
            $videoDetail = (array) $media->getAttribute('video_detail');
            $videoId     = $videoDetail['yt:videoId'] ?? null;
            $rawDuration = (int) $media->getAttribute('duration');
            $duration    = $rawDuration > 0
                ? sprintf('%d:%02d', intdiv($rawDuration, 60), $rawDuration % 60)
                : '';
            $publishedAt = $media->getAttribute('published_at');

            return [
                'title'             => (string) $media->getAttribute('title'),
                'channel'           => (string) ($media->source?->getAttribute('title') ?? ''),
                'publishedAt'       => $publishedAt
                    ? Carbon::parse($publishedAt)->locale('zh-TW')->diffForHumans()
                    : '',
                'duration'          => $duration,
                'thumbnailGradient' => $gradients[$index % count($gradients)],
                'thumbnailEmoji'    => $emojis[$index % count($emojis)],
                'tldr'              => (string) ($summary?->getAttribute('text')['short_summary'] ?? ''),
                'keyPoints'         => (array) ($summary?->getAttribute('text')['long_summary']['key_points'] ?? []),
                'viewCount'         => (int) ($videoDetail['statistics']['viewCount'] ?? 0),
                'url'               => $videoId
                    ? 'https://www.youtube.com/watch?v=' . $videoId
                    : '',
            ];
        })->all();
    }
}
