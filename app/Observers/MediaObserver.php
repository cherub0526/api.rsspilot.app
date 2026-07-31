<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Media;

class MediaObserver
{
    /**
     * Handle the Media "updated" event.
     */
    public function saved(Media $media): void
    {
    }
}
