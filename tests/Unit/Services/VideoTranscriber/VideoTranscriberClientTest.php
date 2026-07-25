<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VideoTranscriber;

use Tests\TestCase;
use Hypervel\Support\Facades\Http;
use App\Services\VideoTranscriber\SignatureGenerator;
use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * @internal
 * @coversNothing
 */
class VideoTranscriberClientTest extends TestCase
{
    protected const SECRET_KEY = 'nc_c7202108-c6bd-11f0-83be-5b08326e553f';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.videotranscriber.secret_key', self::SECRET_KEY);
    }

    public function testStartTranscriptionSendsACorrectlySignedRequest(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient())->startTranscription([
            'path'       => 'https://www.youtube.com/watch?v=eniH9csPEzc',
            'type'       => 3,
            'audio_time' => 158,
            'file_name'  => 'Is hahababy destroying the evidence?',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            if ($request->url() !== 'https://videotranscriber.ai/api/v1/transcriptions/start') {
                return false;
            }

            $expectedSign = (new SignatureGenerator())->generate($body, self::SECRET_KEY);

            return $body['sign'] === $expectedSign
                && $body['path'] === 'https://www.youtube.com/watch?v=eniH9csPEzc'
                && $body['type'] === 3
                && $body['audio_time'] === 158
                && $body['file_name'] === 'Is hahababy destroying the evidence?'
                && $body['source'] === 'web'
                && $body['diarization'] === true
                && $body['ai_enhance'] === true
                && $body['accuracy'] === 'medium'
                && is_int($body['t']);
        });
    }

    public function testCallerSuppliedFieldsOverrideDefaults(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient())->startTranscription([
            'path'        => 'https://www.youtube.com/watch?v=eniH9csPEzc',
            'type'        => 3,
            'audio_time'  => 158,
            'file_name'   => 'Is hahababy destroying the evidence?',
            'diarization' => false,
            'accuracy'    => 'high',
        ]);

        Http::assertSent(fn ($request) => $request->data()['diarization'] === false
            && $request->data()['accuracy'] === 'high');
    }

    public function testReturnsTheDecodedJsonResponse(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        $result = (new VideoTranscriberClient())->startTranscription([
            'path'       => 'https://www.youtube.com/watch?v=eniH9csPEzc',
            'type'       => 3,
            'audio_time' => 158,
            'file_name'  => 'Is hahababy destroying the evidence?',
        ]);

        $this->assertSame(['code' => 100000, 'message' => 'success'], $result);
    }

    public function testStartTranscriptionSendsTheCookieHeaderWhenProvided(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient(cookie: 'session=abc123'))->startTranscription([
            'path' => 'https://www.youtube.com/watch?v=eniH9csPEzc',
            'type' => 3,
        ]);

        Http::assertSent(fn ($request) => $request->header('Cookie') === ['session=abc123']);
    }

    public function testStartTranscriptionOmitsTheCookieHeaderWhenNotProvided(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient())->startTranscription([
            'path' => 'https://www.youtube.com/watch?v=eniH9csPEzc',
            'type' => 3,
        ]);

        Http::assertSent(fn ($request) => $request->header('Cookie') === []);
    }

    public function testGetUrlInfoSendsTheCookieHeaderWhenProvided(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient(cookie: 'session=abc123'))->getUrlInfo('https://www.youtube.com/watch?v=uXHNRFHWDnM');

        Http::assertSent(fn ($request) => $request->header('Cookie') === ['session=abc123']);
    }

    public function testGetUrlInfoSendsTheExpectedQueryParameters(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient())->getUrlInfo('https://www.youtube.com/watch?v=uXHNRFHWDnM');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://videotranscriber.ai/api/v1/transcriptions/url-info')
                && $request['url'] === 'https://www.youtube.com/watch?v=uXHNRFHWDnM'
                && $request['type'] === 3
                && $request['action'] === 'transcribe';
        });
    }

    public function testGetUrlInfoAllowsOverridingTypeAndAction(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient())->getUrlInfo('https://www.youtube.com/watch?v=uXHNRFHWDnM', 1, 'download');

        Http::assertSent(fn ($request) => $request['type'] === 1
            && $request['action'] === 'download');
    }

    public function testGetUrlInfoReturnsTheDecodedJsonResponse(): void
    {
        $data = [
            'code'    => 100000,
            'message' => 'success',
            'data'    => [
                'type'               => 3,
                'title'              => '8 Functions you might not know about your Mercedes-Benz',
                'audio_time'         => 139,
                'thumbnail_url'      => 'https://i.ytimg.com/vi/uXHNRFHWDnM/hqdefault.jpg',
                'videos'             => [],
                'audios'             => [],
                'youtube_video_data' => [
                    'videoId'   => 'uXHNRFHWDnM',
                    'videoInfo' => [
                        'name'         => '8 Functions you might not know about your Mercedes-Benz',
                        'thumbnailUrl' => [
                            'hqdefault'     => 'https://i.ytimg.com/vi/uXHNRFHWDnM/hqdefault.jpg',
                            'maxresdefault' => 'https://i.ytimg.com/vi_webp/uXHNRFHWDnM/maxresdefault.webp',
                        ],
                        'embedUrl'   => 'https://www.youtube.com/embed/uXHNRFHWDnM',
                        'duration'   => 139,
                        'author'     => 'MB212',
                        'channel_id' => 'UCPblPw-MSsRVMQyz4BiBXpA',
                    ],
                ],
                'youtube_has_subtitles' => false,
            ],
        ];

        Http::fake([
            'videotranscriber.ai/*' => Http::response($data, 200),
        ]);

        $result = (new VideoTranscriberClient())->getUrlInfo('https://www.youtube.com/watch?v=uXHNRFHWDnM');

        $this->assertSame($data, $result);
    }
}
