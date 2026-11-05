<?php

declare(strict_types=1);

namespace Tests\Unit\Concerns;

use Tests\TestCase;
use Tests\Concerns\MatchesContractFixture;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 契約比對本身的行為（hidden-plan-link T05）：故意改掉一個 key 或型別時要抓得到。
 *
 * @internal
 * @coversNothing
 */
class MatchesContractFixtureTest extends TestCase
{
    use MatchesContractFixture;

    private const CONTRACT = [
        'id'     => 'string',
        'hidden' => 'boolean',
        'prices' => [['price' => 'number', 'trial_days' => 'number|null']],
    ];

    private const VALID = [
        'id'     => 'p1',
        'hidden' => true,
        'prices' => [['price' => 24.99, 'trial_days' => null], ['price' => 249, 'trial_days' => 7]],
    ];

    public function testAMatchingResponseHasNoMismatches(): void
    {
        $this->assertSame([], $this->contractMismatches(self::VALID, self::CONTRACT));
        $this->assertSame([], $this->contractMismatches([...self::VALID, 'prices' => []], self::CONTRACT));
    }

    #[DataProvider('brokenResponses')]
    public function testABrokenResponseIsReported(array $actual, string $expected): void
    {
        $this->assertContains($expected, $this->contractMismatches($actual, self::CONTRACT));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function brokenResponses(): array
    {
        $valid = self::VALID;

        return [
            'missing key'          => [array_diff_key($valid, ['hidden' => true]), '$.hidden: missing'],
            'extra key'            => [[...$valid, 'access_code' => 'x'], '$.access_code: not in the contract'],
            'renamed key'          => [[...array_diff_key($valid, ['id' => true]), 'plan_id' => 'p1'], '$.id: missing'],
            'wrong scalar type'    => [[...$valid, 'hidden' => 'true'], '$.hidden: expected boolean, got string'],
            'null not allowed'     => [[...$valid, 'id' => null], '$.id: expected string, got null'],
            'nested key missing'   => [[...$valid, 'prices' => [['trial_days' => null]]], '$.prices[0].price: missing'],
            'object, not an array' => [[...$valid, 'prices' => ['price' => 1, 'trial_days' => null]], '$.prices: expected an array'],
            'wrapped in data'      => [['data' => $valid], '$.data: not in the contract'],
        ];
    }

    public function testTheHiddenPlanFixtureIsReadable(): void
    {
        $contract = $this->contractFixture('plans-hidden-show');

        $this->assertSame('boolean', $contract['hidden']);
        $this->assertArrayNotHasKey('access_code', $contract);
        $this->assertArrayNotHasKey('data', $contract);
    }
}
