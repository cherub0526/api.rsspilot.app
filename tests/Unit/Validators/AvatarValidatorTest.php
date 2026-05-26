<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use Tests\TestCase;
use App\Validators\AvatarValidator;

/**
 * @internal
 * @coversNothing
 */
class AvatarValidatorTest extends TestCase
{
    public function testStoreRulesRequiresFile(): void
    {
        $v = new AvatarValidator([]);
        $v->setStoreRules();

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('file', $v->errors()->toArray());
    }

    public function testStoreRulesHasExpectedRules(): void
    {
        $v = new AvatarValidator([]);
        $v->setStoreRules();

        $rules = $v->getRules();

        $this->assertArrayHasKey('file', $rules);
        $this->assertStringContainsString('required', $rules['file']);
        $this->assertStringContainsString('image', $rules['file']);
        $this->assertStringContainsString('max:2048', $rules['file']);
    }
}
