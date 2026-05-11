<?php

declare(strict_types=1);

namespace App\Relations;

use Hyperf\Database\Model\Relations\BelongsToMany;
use Hyperf\Stringable\Str;

/**
 * A BelongsToMany relation that automatically injects a ULID 'id'
 * into each pivot row on attach(), supporting pivot tables with a
 * ULID primary key.
 */
class UlidBelongsToMany extends BelongsToMany
{
    /**
     * Create a new pivot attachment record, injecting a ULID primary key.
     *
     * @param mixed $id
     * @param bool  $timed
     */
    protected function baseAttachRecord($id, $timed): array
    {
        $record = parent::baseAttachRecord($id, $timed);

        $record['id'] = strtolower((string) Str::ulid());

        return $record;
    }
}
