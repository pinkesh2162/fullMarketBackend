<?php

namespace App\Services\FirebaseMigration;

use Illuminate\Support\Facades\File;

class MigrationLogger
{
    public function __construct(
        protected string $path
    ) {}

    public function skip(string $entity, string $reason, array $context = []): void
    {
        $this->line('SKIP', $entity, $reason, $context);
    }

    public function error(string $entity, string $reason, array $context = []): void
    {
        $this->line('ERROR', $entity, $reason, $context);
    }

    public function info(string $entity, string $message, array $context = []): void
    {
        $this->line('INFO', $entity, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function line(string $level, string $entity, string $message, array $context): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $row = [
            'ts' => now()->toIso8601String(),
            'level' => $level,
            'entity' => $entity,
            'message' => $message,
            'context' => $context === [] ? new \stdClass : $context,
        ];

        file_put_contents(
            $this->path,
            json_encode($row, JSON_UNESCAPED_UNICODE)."\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
