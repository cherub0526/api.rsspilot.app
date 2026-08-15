<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\YoutubeService;

/**
 * YoutubeService::getVideoIdFromUrl() —— 純字串解析，不碰任何 API。
 *
 * @internal
 * @coversNothing
 */
class YoutubeServiceVideoIdTest extends TestCase
{
    private function service(): YoutubeService
    {
        return new YoutubeService();
    }

    /**
     * @dataProvider validUrlProvider
     */
    public function testExtractsVideoIdFromSupportedUrlForms(string $url): void
    {
        $this->assertSame('uXHNRFHWDnM', $this->service()->getVideoIdFromUrl($url));
    }

    public static function validUrlProvider(): array
    {
        return [
            'watch'              => ['https://www.youtube.com/watch?v=uXHNRFHWDnM'],
            'watch without www'  => ['https://youtube.com/watch?v=uXHNRFHWDnM'],
            'watch with extras'  => ['https://www.youtube.com/watch?v=uXHNRFHWDnM&t=30s&list=PLabc'],
            'short link'         => ['https://youtu.be/uXHNRFHWDnM'],
            'short link with ts' => ['https://youtu.be/uXHNRFHWDnM?t=30'],
            'shorts'             => ['https://www.youtube.com/shorts/uXHNRFHWDnM'],
            'embed'              => ['https://www.youtube.com/embed/uXHNRFHWDnM'],
            'live'               => ['https://www.youtube.com/live/uXHNRFHWDnM'],
            'mobile'             => ['https://m.youtube.com/watch?v=uXHNRFHWDnM'],
        ];
    }

    /**
     * @dataProvider invalidUrlProvider
     */
    public function testReturnsNullForUnsupportedUrls(string $url): void
    {
        $this->assertNull($this->service()->getVideoIdFromUrl($url));
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'channel url'        => ['https://www.youtube.com/@googledevelopers'],
            'playlist url'       => ['https://www.youtube.com/playlist?list=PLabcdefg'],
            'watch without v'    => ['https://www.youtube.com/watch?list=PLabcdefg'],
            'other host'         => ['https://vimeo.com/watch?v=uXHNRFHWDnM'],
            'lookalike host'     => ['https://notyoutube.com.evil.tld/watch?v=uXHNRFHWDnM'],
            'suffix lookalike'   => ['https://evilyoutube.com/watch?v=uXHNRFHWDnM'],
            'youtu.be lookalike' => ['https://evilyoutu.be/uXHNRFHWDnM'],
            'id too short'       => ['https://www.youtube.com/watch?v=abc'],
            'id too long'        => ['https://www.youtube.com/watch?v=uXHNRFHWDnMxxxx'],
            'id bad characters'  => ['https://www.youtube.com/watch?v=uXHNRFHWD!!'],
            'not a url'          => ['just some text'],
            'empty'              => [''],
        ];
    }
}
