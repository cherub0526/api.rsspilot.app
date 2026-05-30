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
        $media = Media::with('source')->find($mediaId);

        if (!$media) {
            throw new NotFoundHttpException();
        }

        if (!$media->isAccessibleBy($request->user())) {
            throw new NotFoundHttpException();
        }

        return $media;
    }
}
