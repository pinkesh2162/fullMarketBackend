<?php

namespace App\Services\FirebaseMigration;

use Illuminate\Support\Facades\Log;

class FirestoreExportReader
{
    public function __construct(
        protected string $exportsRoot,
        protected int $jsonMaxDepth
    ) {}

    /**
     * @return list<string>
     */
    public function jsonFiles(string $collectionFolder): array
    {
        $dir = rtrim($this->exportsRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$collectionFolder;
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];

        return array_values(array_filter($files, is_file(...)));
    }

    /**
     * @return array{__id?:string,__path?:string,data?:array<string, mixed>}|null
     */
    public function readDocument(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $json = json_decode($raw, true, $this->jsonMaxDepth);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('firebase-migration: json decode failed', [
                'path' => $path,
                'error' => json_last_error_msg(),
            ]);

            return null;
        }

        return is_array($json) ? $json : null;
    }
}
