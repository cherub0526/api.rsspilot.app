<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * 跨 repo 的回應契約（hidden-plan-link T05）。
 *
 * fixture 放在 `tests/fixtures/contracts/<name>.json`，前端 repo 有一份相同的複本。
 * 格式：物件的 key 集合必須完全相同（多一個、少一個都算錯）；陣列寫一個元素，
 * 代表每個元素的形狀；純量寫型別 `string`、`number`、`boolean`、`null`，可以用 `|` 組合。
 */
trait MatchesContractFixture
{
    /** 讀 fixture 的 `response`。 */
    protected function contractFixture(string $name): array
    {
        $path = dirname(__DIR__) . "/fixtures/contracts/{$name}.json";
        $this->assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json['response'] ?? null, "{$name}.json has a response");

        return $json['response'];
    }

    protected function assertMatchesContract(string $name, mixed $actual): void
    {
        $mismatches = $this->contractMismatches($actual, $this->contractFixture($name));
        $this->assertSame([], $mismatches, "the response does not match tests/fixtures/contracts/{$name}.json");
    }

    /** @return list<string> 每一筆不符合的地方，空陣列代表符合 */
    protected function contractMismatches(mixed $actual, mixed $contract, string $path = '$'): array
    {
        if (is_string($contract)) {
            $type = match (true) {
                is_string($actual)                   => 'string',
                is_int($actual) || is_float($actual) => 'number',
                is_bool($actual)                     => 'boolean',
                $actual === null                     => 'null',
                default                              => 'object or array',
            };

            return in_array($type, explode('|', $contract), true) ? [] : ["{$path}: expected {$contract}, got {$type}"];
        }

        if (array_is_list($contract)) {
            if (!is_array($actual) || !array_is_list($actual)) {
                return ["{$path}: expected an array"];
            }
            $out = [];
            foreach ($actual as $i => $item) {
                array_push($out, ...$this->contractMismatches($item, $contract[0], "{$path}[{$i}]"));
            }

            return $out;
        }

        if (!is_array($actual) || ($actual !== [] && array_is_list($actual))) {
            return ["{$path}: expected an object"];
        }
        $out = [];
        foreach (array_diff(array_keys($contract), array_keys($actual)) as $missing) {
            $out[] = "{$path}.{$missing}: missing";
        }
        foreach (array_diff(array_keys($actual), array_keys($contract)) as $extra) {
            $out[] = "{$path}.{$extra}: not in the contract";
        }
        foreach (array_intersect_key($contract, $actual) as $key => $sub) {
            array_push($out, ...$this->contractMismatches($actual[$key], $sub, "{$path}.{$key}"));
        }

        return $out;
    }
}
