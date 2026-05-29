<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media\Chat;

use App\Models\Media;
use Hypervel\Http\Request;
use App\Exceptions\NotFoundHttpException;

trait ResolvesMedia
{
    /**
     * 驗證媒體存取權限，回傳 Media 實例。
     *
     * @throws NotFoundHttpException
     */
    private function resolveMedia(Request $request, string $mediaId): Media
    {
        $media = Media::find($mediaId);

        if (!$media) {
            throw new NotFoundHttpException();
        }

        $source = $media->source;
        $hasAccess = ($source?->free ?? false)
            || ($source && $request->user()->sources()->where('sources.id', $source->getKey())->exists())
            || $request->user()->media()->where('media.id', $mediaId)->exists();

        if (!$hasAccess) {
            throw new NotFoundHttpException();
        }

        return $media;
    }
}
