<?php

declare(strict_types=1);

namespace App\Utils\AI;

use App\Services\VideoTranscriber\Prompts\SmartSummaryTemplate;

/**
 * The one decoder for the JSON shape every `Summary::text` is stored in.
 *
 * Shared rather than private to each job because the shape is a contract read
 * far from where it is written — `SummaryResource`, the chat prompts and the
 * daily digest all index into it. A second decoder would drift from this one
 * without anything failing: the first sign would be a summary rendering half
 * empty in one language and not the other.
 *
 * @see SmartSummaryTemplate the prompt that asks for this shape
 */
class SummaryPayload
{
    /**
     * Decode a model's reply into the summary shape, or null when the reply
     * cannot be used.
     *
     * `long_summary.content` is the one field worth failing over — the rest is
     * normalised so a model that omits an optional array does not cost a whole
     * summary. The fenced-block tolerance is deliberate: the prompts forbid
     * code fences, but models add them anyway often enough that discarding an
     * otherwise-good payload over one would be the wrong trade.
     *
     * @return null|array<string, mixed>
     */
    public static function decode(string $response): ?array
    {
        $decoded = json_decode(static::stripCodeFence(trim($response)), true);

        if (!is_array($decoded) || !is_string($decoded['long_summary']['content'] ?? null)) {
            return null;
        }

        return [
            'short_summary' => (string) ($decoded['short_summary'] ?? ''),
            'long_summary'  => [
                'content'    => $decoded['long_summary']['content'],
                'key_points' => array_values((array) ($decoded['long_summary']['key_points'] ?? [])),
                'keywords'   => array_values((array) ($decoded['long_summary']['keywords'] ?? [])),
            ],
        ];
    }

    /**
     * Unwrap a ```json … ``` block, leaving anything else untouched.
     */
    protected static function stripCodeFence(string $response): string
    {
        if (!str_starts_with($response, '```')) {
            return $response;
        }

        // Drop the opening fence with its optional language tag, then the
        // closing one.
        $response = (string) preg_replace('/^```[a-zA-Z]*\R?/', '', $response);

        return rtrim((string) preg_replace('/\R?```$/', '', $response));
    }
}
