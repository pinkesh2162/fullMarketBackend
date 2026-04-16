<?php

namespace App\MediaLibrary;

use Illuminate\Support\Facades\Http;
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;

/**
 * Replaces Spatie's DefaultDownloader (fopen, no timeout) so remote URLs cannot hang the process.
 */
class TimeoutMediaDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        $timeout = (int) config('firebase-migration.media_download_timeout_seconds', 30);
        $timeout = max(5, min(300, $timeout));
        $connect = min(15, $timeout);
        $verify = (bool) config('media-library.media_downloader_ssl', true);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connect)
                ->withOptions(['verify' => $verify])
                ->withHeaders(['User-Agent' => 'FullMarket/MediaLibrary'])
                ->get($url);
        } catch (\Throwable) {
            throw UnreachableUrl::create($url);
        }

        if (! $response->successful()) {
            throw UnreachableUrl::create($url);
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');
        if ($temporaryFile === false) {
            throw UnreachableUrl::create($url);
        }

        file_put_contents($temporaryFile, $response->body());

        return $temporaryFile;
    }
}
