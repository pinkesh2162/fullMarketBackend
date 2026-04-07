<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CategoryImageStorageService
{
    public function relativePathForKey(string $key): string
    {
        $subdir = trim((string) config('categories.public_subdir', 'images/categories'), '/');

        return $subdir.'/'.Str::slug($key).'.png';
    }

    /**
     * Public URL for a locally stored PNG, or null if the file is not on disk yet.
     */
    public function localAssetUrlIfExists(string $key): ?string
    {
        $relative = $this->relativePathForKey($key);
        $full = public_path($relative);
        if (is_file($full)) {
            return asset($relative);
        }

        return null;
    }

    /**
     * Prefer local file; optionally download once when missing; otherwise return remote URL.
     */
    public function resolveUrl(string $normalizedNameKey, string $remoteUrl): string
    {
        $relative = $this->relativePathForKey($normalizedNameKey);
        $full = public_path($relative);
        if (is_file($full)) {
            return asset($relative);
        }

        if (! config('categories.mirror_remote_on_miss', true)) {
            return $remoteUrl;
        }

        return Cache::lock('category-image:'.sha1($normalizedNameKey), 30)->block(5, function () use ($full, $relative, $remoteUrl) {
            if (is_file($full)) {
                return asset($relative);
            }
            try {
                $this->downloadToPath($full, $remoteUrl);
            } catch (\Throwable $e) {
                report($e);

                return $remoteUrl;
            }

            return asset($relative);
        });
    }

    /**
     * Same as resolveUrl but tolerates invalid config (falls back to local default when possible).
     */
    public function resolveUrlForKey(string $key, ?string $remoteUrl): string
    {
        if (is_string($remoteUrl) && filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            return $this->resolveUrl($key, $remoteUrl);
        }

        $local = $this->localAssetUrlIfExists($key);
        if ($local !== null) {
            return $local;
        }

        $fallback = $this->localAssetUrlIfExists('default');

        return $fallback ?? asset($this->relativePathForKey('default'));
    }

    /**
     * Force download (used by Artisan). Overwrites when $force is true.
     */
    public function downloadKeyToPublic(string $normalizedNameKey, string $remoteUrl, bool $force = false): bool
    {
        $relative = $this->relativePathForKey($normalizedNameKey);
        $full = public_path($relative);
        if (! $force && is_file($full)) {
            return false;
        }
        $this->downloadToPath($full, $remoteUrl);

        return true;
    }

    protected function downloadToPath(string $absolutePath, string $remoteUrl): void
    {
        $response = Http::timeout(60)
            ->withHeaders(['User-Agent' => 'FullMarketCategoryImage/1.0'])
            ->get($remoteUrl);

        if (! $response->successful()) {
            throw new \RuntimeException("Failed to download category image (HTTP {$response->status()}): {$remoteUrl}");
        }

        $bytes = $response->body();
        if (strlen($bytes) < 32) {
            throw new \RuntimeException("Invalid or empty image response: {$remoteUrl}");
        }

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $bytes);
    }
}
