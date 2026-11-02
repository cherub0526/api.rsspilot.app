<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use Tests\TestCase;
use App\Validators\SourceValidator;

/**
 * @internal
 * @coversNothing
 */
class SourceValidatorTest extends TestCase
{
    public function testPreviewRulesRequireUrlAndType(): void
    {
        $v = (new SourceValidator([]))->setPreviewRules();

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('url', $v->errors()->toArray());
        $this->assertArrayHasKey('type', $v->errors()->toArray());
    }

    public function testPreviewRulesPassWithChannelUrl(): void
    {
        $v = (new SourceValidator(['url' => 'https://www.youtube.com/@x', 'type' => 'channel']))->setPreviewRules();

        $this->assertTrue($v->passes());
    }

    public function testPreviewRulesRejectUnknownType(): void
    {
        $v = (new SourceValidator(['url' => 'https://www.youtube.com/@x', 'type' => 'rss']))->setPreviewRules();

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('type', $v->errors()->toArray());
    }

    public function testStoreRulesAcceptElevenCharVideoIds(): void
    {
        $v = (new SourceValidator([
            'url'       => 'https://www.youtube.com/@x',
            'type'      => 'channel',
            'video_ids' => ['dQw4w9WgXcQ', 'a-b_c1234XY'],
        ]))->setStoreRules();

        $this->assertTrue($v->passes());
    }

    public function testStoreRulesRejectMalformedVideoIds(): void
    {
        $v = (new SourceValidator([
            'url'       => 'https://www.youtube.com/@x',
            'type'      => 'channel',
            'video_ids' => ['yt:video:dQw4w9WgXcQ'],
        ]))->setStoreRules();

        $this->assertFalse($v->passes());
        $this->assertSame(
            __('validators.source.video_ids.regex'),
            $v->errors()->first('video_ids.0')
        );
    }

    public function testStoreRulesRejectNonArrayVideoIds(): void
    {
        $v = (new SourceValidator([
            'url'       => 'https://www.youtube.com/@x',
            'type'      => 'channel',
            'video_ids' => 'dQw4w9WgXcQ',
        ]))->setStoreRules();

        $this->assertFalse($v->passes());
        $this->assertSame(__('validators.source.video_ids.array'), $v->errors()->first('video_ids'));
    }

    public function testStoreRulesRejectMoreThanFiftyVideoIds(): void
    {
        $v = (new SourceValidator([
            'url'       => 'https://www.youtube.com/@x',
            'type'      => 'channel',
            'video_ids' => array_map(fn (int $i) => sprintf('video%06d', $i), range(1, 51)),
        ]))->setStoreRules();

        $this->assertFalse($v->passes());
        $this->assertSame(__('validators.source.video_ids.max'), $v->errors()->first('video_ids'));
    }
}
