<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber;

/**
 * Reassembles the `summary/completions` SSE stream into the finished summary.
 *
 * Every event carries an `id:` and an `event:` line alongside its `data:`. The
 * stream opens with an `event: metadata` chunk holding only `summary_id`, then
 * emits one `event: message` per fragment, and closes with `[DONE]`:
 *
 *     id: 05962e0f9d5f400e9840bab2a7c6c705
 *     event: metadata
 *     data: {"summary_id": "06a7c902cf667ad8800055ccaa380cd4"}
 *
 *     id: 1e397db6e9e944b3bdcc377456e05a93
 *     event: message
 *     data: {"message": "#", "conversation_id": ""}
 *
 *     data: {"message": " Ref", "conversation_id": ""}
 *     data: {"message": "lections", "conversation_id": ""}
 *     data: [DONE]
 *
 * Note this is NOT the OpenAI chunk shape the rest of this codebase consumes
 * (`choices[0].delta.content` in `ChatController`) even though the request
 * takes a `model` — the fragments live in a flat `message` field.
 *
 * Fragments split mid-word and carry significant leading spaces, so they are
 * concatenated verbatim: trimming any of them corrupts the text. The opening
 * `summary_id` is currently discarded, having no consumer.
 */
class SummaryStreamParser
{
    /**
     * The sentinel the stream ends on. Sent as the payload of a normal
     * `event: message`, so it has to be recognised before the payload is
     * treated as a fragment.
     */
    public const DONE = '[DONE]';

    /**
     * The field each chunk carries its fragment of the summary in.
     */
    protected const CONTENT_KEY = 'message';

    /**
     * Concatenate every chunk's text into the finished summary.
     */
    public function parse(string $stream): string
    {
        $summary = '';

        foreach ($this->dataPayloads($stream) as $payload) {
            if ($payload === self::DONE) {
                break;
            }

            $summary .= $this->extractContent($payload);
        }

        return $summary;
    }

    /**
     * Yield the payload of each `data:` line.
     *
     * Comment lines (`:` heartbeats), event/id fields and blank separators are
     * skipped. The optional single space after the colon is part of the SSE
     * format rather than the payload, so it is stripped.
     *
     * @return iterable<string>
     */
    protected function dataPayloads(string $stream): iterable
    {
        foreach (preg_split("/\r\n|\r|\n/", $stream) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }

            yield ltrim(substr($line, strlen('data:')), ' ');
        }
    }

    /**
     * Pull the fragment out of one chunk.
     *
     * Chunks without a `message` — the opening `summary_id` one — contribute
     * nothing. A payload that is not JSON is taken literally: per the SSE
     * format a `data:` line is free-form text, so surfacing it beats returning
     * a summary that is silently short.
     */
    protected function extractContent(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return $payload;
        }

        $message = $decoded[self::CONTENT_KEY] ?? null;

        return is_string($message) ? $message : '';
    }
}
