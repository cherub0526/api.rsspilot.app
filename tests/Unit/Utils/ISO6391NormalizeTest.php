<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use Tests\TestCase;
use App\Utils\Const\ISO6391;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @covers \App\Utils\Const\ISO6391
 */
class ISO6391NormalizeTest extends TestCase
{
    #[DataProvider('codes')]
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, ISO6391::normalize($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function codes(): array
    {
        return [
            'caption style becomes ISO style' => ['zh_tw', 'zh-TW'],
            'already normalised'              => ['zh-TW', 'zh-TW'],
            'lowercase region'                => ['zh-tw', 'zh-TW'],
            'simplified chinese'              => ['zh_cn', 'zh-CN'],
            'plain language untouched'        => ['en', 'en'],
            'uppercase language'              => ['EN', 'en'],
            'surrounding spaces'              => [' en ', 'en'],
            'unregistered region falls back'  => ['zh-HK', 'zh'],
            'unknown code is untouched'       => ['klingon', 'klingon'],
        ];
    }
}
