<?php

namespace App\Services\FirebaseMigration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        if ($resolvedUrl !== null) {
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
        }

        $localPath = $this->resolveLocalMediaPath($url);
        if ($localPath === null) {
            return false;
        }
        try {
            $model->addMedia($localPath)->toMediaCollection($collection, $disk);

            return true;
        } catch (Throwable $e) {
            Log::warning('firebase-migration: media local import failed', [
                'collection' => $collection,
                'path' => $localPath,
                'message' => $e->getMessage(),
            ]);
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
            if ($resolvedUrl !== null) {
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
                continue;
            }

            $localPath = $this->resolveLocalMediaPath($url);
            if ($localPath === null) {
                continue;
            }
            try {
                $ext = $this->guessExtension($localPath);
                $fileName = $fileNamePrefix.'-'.$position.'-'.bin2hex(random_bytes(8)).'.'.$ext;
                $media = $model->addMedia($localPath)
                    ->usingFileName($fileName)
                    ->toMediaCollection($collection, $disk);
                $media->order_column = $position;
                $media->save();
                $n++;
            } catch (Throwable $e) {
                Log::warning('firebase-migration: listing local image', [
                    'collection' => $collection,
                    'path' => $localPath,
                    'message' => $e->getMessage(),
                ]);
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

    protected function resolveLocalMediaPath(string $raw): ?string
    {
        $input = trim($raw);
        if ($input === '') {
            return null;
        }

        if (is_file($input)) {
            return $input;
        }

        $exportsStorage = config('firebase-migration.exports_storage_path', base_path('FirebaseDatabase/exports/storage'));
        if (! is_string($exportsStorage) || trim($exportsStorage) === '') {
            return null;
        }
        $root = rtrim($exportsStorage, DIRECTORY_SEPARATOR);

        $normalized = str_replace('\\', '/', ltrim($input, '/'));
        $normalized = Str::replaceFirst('public/', '', $normalized);

        $candidates = [
            $root.'/'.$normalized,
        ];

        if (str_starts_with($normalized, 'uploads/')) {
            $afterUploads = substr($normalized, strlen('uploads/'));
            $candidates[] = $root.'/'.$afterUploads;
        }

        if (str_contains($normalized, '/uploads/')) {
            $afterMarker = explode('/uploads/', $normalized, 2)[1] ?? null;
            if (is_string($afterMarker) && $afterMarker !== '') {
                $candidates[] = $root.'/'.$afterMarker;
            }
        }

        $baseName = basename($normalized);
        if ($baseName !== '' && $baseName !== '.' && $baseName !== '..') {
            $candidates[] = $root.'/images/'.$baseName;
            $candidates[] = $root.'/store_images/'.$baseName;
            $candidates[] = $root.'/stores/'.$baseName;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
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
