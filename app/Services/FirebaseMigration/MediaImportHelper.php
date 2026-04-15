<?php

namespace App\Services\FirebaseMigration;

use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

class MediaImportHelper
{
    public function __construct(
        protected int $retries,
        protected int $sleepMs
    ) {}

    /**
     * @return bool True if attached
     */
    public function attachFromUrl(HasMedia $model, string $url, string $collection, string $disk): bool
    {
        $resolvedUrl = $this->normalizeMediaUrl($url);
        if ($resolvedUrl === null) {
            return false;
        }

        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            try {
                $model->addMediaFromUrl($resolvedUrl)->toMediaCollection($collection, $disk);

                return true;
            } catch (Throwable $e) {
                Log::warning('firebase-migration: media download failed', [
                    'collection' => $collection,
                    'attempt' => $attempt,
                    'url' => $resolvedUrl,
                    'message' => $e->getMessage(),
                ]);
                if ($attempt < $this->retries) {
                    usleep($this->sleepMs * 1000);
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $urls
     */
    public function attachManyOrdered(HasMedia $model, array $urls, string $collection, string $disk, string $fileNamePrefix): int
    {
        $n = 0;
        foreach ($urls as $position => $url) {
            if (! is_string($url)) {
                continue;
            }
            $resolvedUrl = $this->normalizeMediaUrl($url);
            if ($resolvedUrl === null) {
                continue;
            }
            for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
                try {
                    $ext = $this->guessExtension($resolvedUrl);
                    $fileName = $fileNamePrefix.'-'.$position.'-'.bin2hex(random_bytes(8)).'.'.$ext;
                    $media = $model->addMediaFromUrl($resolvedUrl)
                        ->usingFileName($fileName)
                        ->toMediaCollection($collection, $disk);
                    $media->order_column = $position;
                    $media->save();
                    $n++;
                    break;
                } catch (Throwable $e) {
                    Log::warning('firebase-migration: listing gallery image', [
                        'attempt' => $attempt,
                        'url' => $resolvedUrl,
                        'message' => $e->getMessage(),
                    ]);
                    if ($attempt < $this->retries) {
                        usleep($this->sleepMs * 1000);
                    }
                }
            }
        }

        return $n;
    }

    protected function guessExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        if ($ext === '' || strlen($ext) > 8 || ! preg_match('/^[a-z0-9]+$/', $ext)) {
            return 'jpg';
        }

        return $ext;
    }

    protected function normalizeMediaUrl(string $raw): ?string
    {
        $url = trim($raw);
        if ($url === '') {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }

        $baseUrl = $this->legacyBaseUrl();
        if ($baseUrl === null) {
            return null;
        }

        $path = ltrim($url, '/');
        if (str_starts_with($path, 'uploads/')) {
            $path = 'public/'.$path;
        }
        if (! str_starts_with($path, 'public/uploads/')) {
            return null;
        }

        $resolved = rtrim($baseUrl, '/').'/'.$path;

        return filter_var($resolved, FILTER_VALIDATE_URL) ? $resolved : null;
    }

    protected function legacyBaseUrl(): ?string
    {
        $base = config('firebase-migration.legacy_media_base_url');
        if (! is_string($base)) {
            return null;
        }

        $base = trim($base);
        if ($base === '') {
            return null;
        }

        return filter_var($base, FILTER_VALIDATE_URL) ? $base : null;
    }
}
