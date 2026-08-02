<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VideoTranscriber;

use Tests\TestCase;
use App\Models\Config;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Exceptions\VideoTranscriberAuthException;
use App\Services\VideoTranscriber\SignatureGenerator;
use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * @internal
 * @coversNothing
 */
class VideoTranscriberClientTest extends TestCase
{
    use RefreshDatabase;

    protected const SECRET_KEY = 'nc_c7202108-c6bd-11f0-83be-5b08326e553f';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.videotranscriber.secret_key', self::SECRET_KEY);
        config()->set('services.videotranscriber.email', 'cherub0526@gmail.com');
        config()->set('services.videotranscriber.password', 'secret');
        config()->set('services.videotranscriber.unauthorized_codes', []);
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

    public function testGetTranscriptionSendsTheExpectedQueryParameter(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient())->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://videotranscriber.ai/api/v1/transcriptions?record_id=562a7c09-c6b4-4289-80e6-36a4921b571f'
                && $request['record_id'] === '562a7c09-c6b4-4289-80e6-36a4921b571f';
        });
    }

    public function testGetTranscriptionDoesNotSendACookieHeaderByDefault(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient())->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');

        Http::assertSent(fn ($request) => $request->header('Cookie') === []);
    }

    public function testGetTranscriptionReturnsTheDecodedJsonResponse(): void
    {
        $data = [
            'code'    => 100000,
            'message' => 'success',
            'data'    => [
                'record_id' => '562a7c09-c6b4-4289-80e6-36a4921b571f',
                'status'    => 'completed',
            ],
        ];

        Http::fake([
            'videotranscriber.ai/*' => Http::response($data, 200),
        ]);

        $result = (new VideoTranscriberClient())->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');

        $this->assertSame($data, $result);
    }

    public function testLoginStoresTheResponseDataInConfigs(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => [
                    'access_token' => 'kTg5_uMKq1w4-HAQPgY8lRx4MFP5dJNF4Y80HEswGxA',
                    'token_type'   => 'bearer',
                    'user_name'    => '黃麒錕',
                    'email'        => 'cherub0526@gmail.com',
                    'auth_type'    => 'google',
                ],
            ], 200),
        ]);

        $result = (new VideoTranscriberClient())->login('cherub0526@gmail.com', 'secret');

        $this->assertSame(100000, $result['code']);

        $config = Config::where('key', Config::KEY_VIDEOTRANSCRIBER)->first();
        $this->assertNotNull($config);
        $this->assertSame('kTg5_uMKq1w4-HAQPgY8lRx4MFP5dJNF4Y80HEswGxA', $config->value['access_token']);
        $this->assertSame('黃麒錕', $config->value['user_name']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://videotranscriber.ai/api/v1/auth/email/login'
                && $request['email'] === 'cherub0526@gmail.com'
                && $request['password'] === 'secret';
        });
    }

    public function testLoginDoesNotStoreAnythingWhenTheApiFails(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100001, 'message' => 'invalid credentials'], 200),
        ]);

        (new VideoTranscriberClient())->login('cherub0526@gmail.com', 'wrong');

        $this->assertNull(Config::where('key', Config::KEY_VIDEOTRANSCRIBER)->first());
    }

    public function testLoginOverwritesTheExistingConfig(): void
    {
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'old-token']);

        Http::fake([
            'videotranscriber.ai/*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['access_token' => 'new-token'],
            ], 200),
        ]);

        (new VideoTranscriberClient())->login('cherub0526@gmail.com', 'secret');

        $this->assertSame(1, Config::where('key', Config::KEY_VIDEOTRANSCRIBER)->count());
        $this->assertSame('new-token', Config::getValue(Config::KEY_VIDEOTRANSCRIBER)['access_token']);
    }

    public function testRequestsSendTheStoredAccessTokenAsTheNcTokenCookie(): void
    {
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'stored-token']);

        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient())->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');

        Http::assertSent(fn ($request) => $request->header('Cookie') === ['nc_token=stored-token']);
    }

    public function testAnExplicitCookieTakesPrecedenceOverTheStoredAccessToken(): void
    {
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'stored-token']);

        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100000, 'message' => 'success'], 200),
        ]);

        (new VideoTranscriberClient(cookie: 'session=abc123'))->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');

        Http::assertSent(fn ($request) => $request->header('Cookie') === ['session=abc123']);
    }

    public function testLogsInAgainAndReplaysTheRequestWhenTheTokenIsRejected(): void
    {
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'expired-token']);

        Http::fake([
            'videotranscriber.ai/api/v1/auth/email/login' => Http::response([
                'code' => 100000,
                'data' => ['access_token' => 'fresh-token'],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::sequence()
                ->push(['message' => 'unauthorized'], 401)
                ->push(['code' => 100000, 'message' => 'success'], 200),
        ]);

        $result = (new VideoTranscriberClient())->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');

        $this->assertSame(100000, $result['code']);
        $this->assertSame('fresh-token', Config::getValue(Config::KEY_VIDEOTRANSCRIBER)['access_token']);

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://videotranscriber.ai/api/v1/transcriptions')
            && $request->header('Cookie') === ['nc_token=fresh-token']);
    }

    public function testTreatsAConfiguredBusinessCodeAsAnExpiredToken(): void
    {
        config()->set('services.videotranscriber.unauthorized_codes', [100003]);
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'expired-token']);

        Http::fake([
            'videotranscriber.ai/api/v1/auth/email/login' => Http::response([
                'code' => 100000,
                'data' => ['access_token' => 'fresh-token'],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions/url-info?*' => Http::sequence()
                ->push(['code' => 100003, 'message' => 'please login'], 200)
                ->push(['code' => 100000, 'message' => 'success'], 200),
        ]);

        $result = (new VideoTranscriberClient())->getUrlInfo('https://www.youtube.com/watch?v=uXHNRFHWDnM');

        $this->assertSame(100000, $result['code']);
        $this->assertSame('fresh-token', Config::getValue(Config::KEY_VIDEOTRANSCRIBER)['access_token']);
    }

    public function testDoesNotRetryABusinessCodeThatIsNotConfiguredAsUnauthorized(): void
    {
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'stored-token']);

        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100003, 'message' => 'please login'], 200),
        ]);

        $result = (new VideoTranscriberClient())->getUrlInfo('https://www.youtube.com/watch?v=uXHNRFHWDnM');

        $this->assertSame(100003, $result['code']);
        Http::assertSentCount(1);
    }

    public function testThrowsWhenTheReloginIsRejected(): void
    {
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'expired-token']);

        Http::fake([
            'videotranscriber.ai/api/v1/auth/email/login' => Http::response(
                ['code' => 100001, 'message' => 'invalid credentials'],
                200
            ),
            'videotranscriber.ai/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $this->expectException(VideoTranscriberAuthException::class);

        (new VideoTranscriberClient())->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');
    }

    public function testThrowsWithoutCallingLoginWhenNoCredentialsAreConfigured(): void
    {
        config()->set('services.videotranscriber.email', null);
        config()->set('services.videotranscriber.password', null);
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'expired-token']);

        Http::fake([
            'videotranscriber.ai/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        try {
            (new VideoTranscriberClient())->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');
            $this->fail('Expected a VideoTranscriberAuthException.');
        } catch (VideoTranscriberAuthException) {
            Http::assertNotSent(
                fn ($request) => $request->url() === 'https://videotranscriber.ai/api/v1/auth/email/login'
            );
        }
    }

    public function testReplaysWithTheTokenAnotherWorkerRefreshedInsteadOfLoggingInAgain(): void
    {
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'expired-token']);

        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => function () {
                // Stands in for a concurrent worker that refreshed the token
                // while this request was still in flight.
                if (Config::getValue(Config::KEY_VIDEOTRANSCRIBER)['access_token'] === 'expired-token') {
                    Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'someone-elses-token']);

                    return Http::response(['message' => 'unauthorized'], 401);
                }

                return Http::response(['code' => 100000, 'message' => 'success'], 200);
            },
        ]);

        $result = (new VideoTranscriberClient())->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');

        $this->assertSame(100000, $result['code']);
        Http::assertNotSent(
            fn ($request) => $request->url() === 'https://videotranscriber.ai/api/v1/auth/email/login'
        );
        Http::assertSent(fn ($request) => $request->header('Cookie') === ['nc_token=someone-elses-token']);
    }

    public function testDoesNotReloginWhenAnExplicitCookieWasInjected(): void
    {
        Config::setValue(Config::KEY_VIDEOTRANSCRIBER, ['access_token' => 'stored-token']);

        Http::fake([
            'videotranscriber.ai/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $result = (new VideoTranscriberClient(cookie: 'session=abc123'))
            ->getTranscription('562a7c09-c6b4-4289-80e6-36a4921b571f');

        $this->assertSame(['message' => 'unauthorized'], $result);
        Http::assertSentCount(1);
    }
}
