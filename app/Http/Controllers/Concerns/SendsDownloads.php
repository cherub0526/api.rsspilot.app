<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Psr\Http\Message\ResponseInterface;

/**
 * 把產好的字串當成檔案送出。
 *
 * 收在 trait 裡是因為 Content-Disposition 的組法有一個容易寫錯的地方
 *（見 contentDisposition），每個下載端點各抄一次遲早會有一個抄漏。
 */
trait SendsDownloads
{
    protected function fileResponse(string $content, string $filename, string $mime): ResponseInterface
    {
        return response()->make($content, 200, [
            'Content-Type'        => $mime . '; charset=utf-8',
            'Content-Disposition' => $this->contentDisposition($filename),
            'Content-Length'      => (string) strlen($content),
        ]);
    }

    /**
     * 影片標題直接當檔名會踩到路徑分隔字元與 Windows 的保留字元。
     * 換成底線而不是刪掉，免得「A/B 測試」變成看不懂的「AB 測試」。
     */
    protected function safeFilename(string $name, string $fallback): string
    {
        $cleaned = preg_replace('/[\\\\\/:*?"<>|]/u', '_', $name) ?? '';
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? '';
        $cleaned = mb_substr(ltrim($cleaned, '.'), 0, 120);
        $cleaned = trim($cleaned);

        return $cleaned !== '' ? $cleaned : $fallback;
    }

    /**
     * 中文影片標題是常態，而 Content-Disposition 的 filename= 只認 ASCII——
     * 只給它的話瀏覽器存下來會是一串亂碼或被截斷。照 RFC 6266 同時給兩個：
     * filename= 是舊 client 的後備（非 ASCII 換成底線），filename*= 才是真正的檔名。
     */
    private function contentDisposition(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'download';
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        return sprintf(
            "attachment; filename=\"%s\"; filename*=UTF-8''%s",
            $ascii,
            rawurlencode($filename)
        );
    }
}
