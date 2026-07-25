<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VideoTranscriber;

use Tests\TestCase;
use App\Services\VideoTranscriber\SignatureGenerator;

/**
 * @internal
 * @coversNothing
 */
class SignatureGeneratorTest extends TestCase
{
    protected const SECRET_KEY = 'nc_c7202108-c6bd-11f0-83be-5b08326e553f';

    public function testGeneratesKnownSignatureFromRealCapturedRequest(): void
    {
        $payload = [
            'path'             => 'https://www.youtube.com/watch?v=v_dPtfaKEh0',
            'type'             => 3,
            'lang_code'        => '',
            'diarization'      => true,
            'ai_enhance'       => true,
            'accuracy'         => 'medium',
            'referrer_url'     => '/zh-TW/youtube-transcript-generator',
            'audio_time'       => 285,
            'file_name'        => '您真的需要高階安卓機嗎？車用安卓機等級挑選要訣！【OPTION改裝車訊】',
            'source'           => 'web',
            'client_lang_code' => 'en',
            't'                => 1784741810,
        ];

        $sign = (new SignatureGenerator())->generate($payload, self::SECRET_KEY);

        $this->assertSame(
            '04cd9db8787357252b0e822bbe471a50bd301e50b6408f40066316d34b68b000',
            $sign
        );
    }

    public function testSignatureIsUnaffectedByInputKeyOrder(): void
    {
        $payload = [
            'path'             => 'https://www.youtube.com/watch?v=v_dPtfaKEh0',
            'type'             => 3,
            'lang_code'        => '',
            'diarization'      => true,
            'ai_enhance'       => true,
            'accuracy'         => 'medium',
            'referrer_url'     => '/zh-TW/youtube-transcript-generator',
            'audio_time'       => 285,
            'file_name'        => '您真的需要高階安卓機嗎？車用安卓機等級挑選要訣！【OPTION改裝車訊】',
            'source'           => 'web',
            'client_lang_code' => 'en',
            't'                => 1784741810,
        ];

        $shuffled = array_reverse($payload, true);

        $generator = new SignatureGenerator();

        $this->assertSame(
            $generator->generate($payload, self::SECRET_KEY),
            $generator->generate($shuffled, self::SECRET_KEY)
        );
    }

    public function testExistingSignFieldIsExcludedFromTheSignedMessage(): void
    {
        $payload = [
            'path' => 'https://www.youtube.com/watch?v=v_dPtfaKEh0',
            'type' => 3,
            't'    => 1784741810,
        ];

        $generator = new SignatureGenerator();

        $this->assertSame(
            $generator->generate($payload, self::SECRET_KEY),
            $generator->generate($payload + ['sign' => 'stale-or-forged-value'], self::SECRET_KEY)
        );
    }

    public function testBooleanFieldsAreStringifiedAsLowercaseTrueFalse(): void
    {
        $generator = new SignatureGenerator();

        $enabled = $generator->generate(['diarization' => true], self::SECRET_KEY);
        $disabled = $generator->generate(['diarization' => false], self::SECRET_KEY);
        $stringTrue = $generator->generate(['diarization' => 'true'], self::SECRET_KEY);

        $this->assertNotSame($enabled, $disabled);
        $this->assertSame($enabled, $stringTrue);
    }

    public function testUsesSecretKeyFromConfigWhenNoneIsProvided(): void
    {
        config()->set('services.videotranscriber.secret_key', self::SECRET_KEY);

        $payload = ['path' => 'https://www.youtube.com/watch?v=v_dPtfaKEh0', 't' => 1784741810];

        $generator = new SignatureGenerator();

        $this->assertSame(
            $generator->generate($payload, self::SECRET_KEY),
            $generator->generate($payload)
        );
    }
}
