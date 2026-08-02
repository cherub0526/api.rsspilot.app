<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown when videotranscriber.ai rejects the stored access token and the
 * automatic re-login could not obtain a working one.
 *
 * It is deliberately distinct from a generic request failure: the media is
 * still perfectly processable, only the shared account is unusable right now,
 * so callers should back off and retry instead of marking the media failed.
 */
class VideoTranscriberAuthException extends Exception
{
}
