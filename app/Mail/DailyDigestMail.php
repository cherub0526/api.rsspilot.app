<?php

declare(strict_types=1);

namespace App\Mail;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Media;
use App\Models\Summary;
use Hypervel\Mail\Mailable;
use Hypervel\Queue\Queueable;
use Hypervel\Support\Facades\App;
use Hypervel\Queue\SerializesModels;
use Hypervel\Database\Eloquent\Collection;

class DailyDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * `$videos` 必須維持非 public：`Mailable::buildViewData()` 會把所有 public
     * 屬性塞進版型變數，而且蓋過 `view()` 傳進去的同名資料——留成 public 的話，
     * 版型裡的 `$videos` 會變成 Media 模型集合而不是下面組出來的陣列，
     * `$video['keyPoints']` 取到 null，`@foreach` 直接炸掉。
     *
     * @param Collection<int, Media> $videos Media models with loaded `summary` and `source` relations
     */
    public function __construct(
        public readonly User $user,
        protected readonly Collection $videos,
    ) {
    }

    /**
     * 日期與相對時間的語系。
     *
     * Mailable::send() 會把 build() 整段包在 withLocale($this->locale) 裡跑
     *（見 Hypervel\Mail\Mailable），而 DailyDigestJob 送的是使用者自己的
     * uiLocale()，所以這裡讀 App::getLocale() 就是那位收件人的語系。
     * 原本寫死 'zh-TW'，英文使用者會收到中文的日期與「5 小時前」。
     */
    private function dateLocale(): string
    {
        return App::getLocale();
    }

    /**
     * 信裡的 logo 網址。
     *
     * **不能用 `asset()`。** 它最終走 `UrlGenerator::getRequestUri()`，有 request 時
     * 取當前請求的 host、沒有時才退回 `config('app.url')`——而信是在 queue worker
     * 裡算出來的，永遠沒有 request。更麻煩的是它的 scheme 快取在 UrlGenerator 這個
     * singleton 上，在常駐的 Swoole 程序裡是跨請求狀態。直接讀設定值，行為才是
     * 一望即知且可測的。
     *
     * `APP_URL` **必須設定**。沒設定時 `config('app.url')` 會退回 `http://localhost`，
     * 信裡的圖就指向收件人自己的電腦——不會報錯，只會在每一封信裡默默破圖。
     * public/logo.png 由 Swoole 的 static handler 直接供應（見 config/server.php 的
     * `enable_static_handler`），所以 `{APP_URL}/logo.png` 就是它的公開位址。
     */
    private function logoUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/logo.png';
    }

    public function build(): self
    {
        $clientUrl = rtrim((string) env('CLIENT_URL', ''), '/');

        return $this->subject(__('mails.daily_digest.subject', ['count' => $this->videos->count()]))
            ->view('emails.daily-digest', [
                'logoUrl'  => $this->logoUrl(),
                'userName' => (string) $this->user->getAttribute('name'),
                'date'     => Carbon::now()
                    ->locale($this->dateLocale())
                    ->isoFormat(__('mails.daily_digest.date_format')),
                'videoCount'      => $this->videos->count(),
                'videos'          => $this->buildVideoList(),
                'channelCount'    => $this->user->sources()->count(),
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
            // 與 /summaries、chat 取同一份摘要（見 Media::summaryFor()），只取
            // 已完成的——信裡直接顯示內容，撈到還沒填的空殼就是一封空信。
            /** @var null|Summary $summary */
            $summary = $media->summaryFor($this->user, true);
            $videoDetail = (array) $media->getAttribute('video_detail');
            $videoId = $videoDetail['yt:videoId'] ?? null;
            $rawDuration = (int) $media->getAttribute('duration');
            $duration = $rawDuration > 0
                ? sprintf('%d:%02d', intdiv($rawDuration, 60), $rawDuration % 60)
                : '';
            $publishedAt = $media->getAttribute('published_at');

            // 頻道／清單的縮圖。sources.thumbnail 存的已經是完整網址（YouTube 的
            // yt3.ggpht.com 或 i.ytimg.com），不需要再接前綴。取不到時回空字串，
            // 由版型退回原本的漸層底色——寧可少一張圖，也不要送出破圖的 <img>。
            $channelThumbnail = (string) ($media->source?->getAttribute('thumbnail') ?? '');

            return [
                'title'            => (string) $media->getAttribute('title'),
                'channel'          => (string) ($media->source?->getAttribute('title') ?? ''),
                'channelThumbnail' => $channelThumbnail,
                'publishedAt'      => $publishedAt
                    ? Carbon::parse($publishedAt)->locale($this->dateLocale())->diffForHumans()
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
