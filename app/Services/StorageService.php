<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeInterface;
use Hypervel\Support\Facades\Storage;

class StorageService
{
    /**
     * Upload a file to S3.
     *
     * @param string $source the absolute path to the source file
     * @param string $destination the destination path on S3
     */
    public function upload(string $source, string $destination): bool
    {
        if (!file_exists($source)) {
            return false;
        }

        $stream = fopen($source, 'r');

        try {
            return Storage::disk('s3')->put($destination, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * 檔案是否已存在於 S3。
     *
     * 只發一次 HEAD，不取回內容——呼叫端要的是「該不該上傳」這個布林，
     * 為此把物件抓下來等於把要省的頻寬又付一次。
     *
     * @param string $path the path to the file on S3
     */
    public function exists(string $path): bool
    {
        return Storage::disk('s3')->exists($path);
    }

    /**
     * Generate a temporary shared link for the file.
     *
     * @param string $path the path to the file on S3
     * @param DateTimeInterface $expiration the expiration time
     */
    public function getTemporaryUrl(string $path, DateTimeInterface $expiration): string
    {
        return Storage::disk('s3')->temporaryUrl($path, $expiration);
    }
}
