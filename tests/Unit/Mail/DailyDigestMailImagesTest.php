<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\Summary;
use App\Mail\DailyDigestMail;
use Hypervel\Support\Facades\Config;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 信件裡的圖片網址。
 *
 * 這一組刻意獨立於 DailyDigestJobTest：圖片壞掉不會讓任何測試變紅，也不會拋錯，
 * 只會在收件匣裡默默破圖，所以要有專門盯著網址長相的斷言。
 *
 * @internal
 * @coversNothing
 */
class DailyDigestMailImagesTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://api.example.com';

    private const CHANNEL_THUMBNAIL = 'https://yt3.ggpht.com/channel-avatar=s800-c-k-c0x00ffffff-no-rj';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.url', self::APP_URL);
    }

    public function testLogoUsesAnAbsoluteUrlBuiltFromAppUrl(): void
    {
        $html = $this->render($this->sourceWithThumbnail());

        // 兩處 logo（信頭與頁尾）都必須是絕對網址。相對路徑在信件裡無從解析——
        // 這正是原本用 asset() 的下場：queue worker 沒有 request，APP_URL 又沒設定。
        $this->assertStringContainsString('src="' . self::APP_URL . '/logo.png"', $html);
        $this->assertStringNotContainsString('src="/logo.png"', $html);
        $this->assertSame(2, substr_count($html, self::APP_URL . '/logo.png'));
    }

    public function testLogoUrlHasNoDoubleSlashWhenAppUrlHasATrailingSlash(): void
    {
        Config::set('app.url', self::APP_URL . '/');

        $html = $this->render($this->sourceWithThumbnail());

        $this->assertStringContainsString('src="' . self::APP_URL . '/logo.png"', $html);
        $this->assertStringNotContainsString('//logo.png', $html);
    }

    public function testVideoCardUsesTheChannelThumbnail(): void
    {
        $html = $this->render($this->sourceWithThumbnail());

        $this->assertStringContainsString('src="' . self::CHANNEL_THUMBNAIL . '"', $html);
    }

    public function testFallsBackToTheGradientWhenTheSourceHasNoThumbnail(): void
    {
        $source = Source::factory()->create(['thumbnail' => null]);

        $html = $this->render($source);

        // 缺縮圖時絕不能送出 src 為空的 <img>——多數客戶端會畫成破圖框。
        $this->assertStringNotContainsString('class="video-thumb-img"', $html);
        $this->assertStringContainsString('video-thumb-inner', $html);
        $this->assertStringContainsString('linear-gradient', $html);
    }

    private function sourceWithThumbnail(): Source
    {
        return Source::factory()->create(['thumbnail' => self::CHANNEL_THUMBNAIL]);
    }

    private function render(Source $source): string
    {
        $user = User::factory()->create();
        $user->sources()->attach($source->id, ['notify' => true]);

        $media = Media::factory()->create([
            'source_id' => $source->id,
            'status'    => Media::STATUS_SUMMARIZED,
        ]);

        Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => Summary::LOCALE_EN,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['short_summary' => 'tldr', 'long_summary' => ['key_points' => ['a']]],
        ]);

        $user->media()->attach($media->id);

        $videos = Media::query()->with('source')->whereKey($media->id)->get();

        return (new DailyDigestMail($user, $videos))->render();
    }
}
