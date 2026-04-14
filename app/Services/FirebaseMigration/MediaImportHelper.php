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
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            try {
                $model->addMediaFromUrl($url)->toMediaCollection($collection, $disk);

                return true;
            } catch (Throwable $e) {
                Log::warning('firebase-migration: media download failed', [
                    'collection' => $collection,
                    'attempt' => $attempt,
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
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
                try {
                    $ext = $this->guessExtension($url);
                    $fileName = $fileNamePrefix.'-'.$position.'-'.bin2hex(random_bytes(8)).'.'.$ext;
                    $media = $model->addMediaFromUrl($url)
                        ->usingFileName($fileName)
                        ->toMediaCollection($collection, $disk);
                    $media->order_column = $position;
                    $media->save();
                    $n++;
                    break;
                } catch (Throwable $e) {
                    Log::warning('firebase-migration: listing gallery image', [
                        'attempt' => $attempt,
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
}
