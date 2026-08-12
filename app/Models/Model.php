<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Model as BaseModel;

abstract class Model extends BaseModel
{
    protected ?string $connection = null;

    /**
     * Encode a JSON-cast attribute keeping the characters it was given.
     *
     * The framework's default `json_encode()` escapes every non-ASCII
     * character to a `\uXXXX` sequence and every `/` to `\/`, so a Chinese
     * caption lands in the column as an unreadable run of escapes and a URL
     * as `https:\/\/…`. Round-tripping through the cast still works, but the
     * column becomes opaque to anyone querying it by hand, and escaping
     * inflates CJK payloads roughly threefold — which matters for the columns
     * already close to their limit.
     *
     * Decoding is unaffected — `json_decode()` accepts both forms — so rows
     * written before this override keep working untouched.
     */
    protected function asJson(mixed $value): false|string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
