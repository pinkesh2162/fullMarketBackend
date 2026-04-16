<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseMigration\FirestoreMigrationService;
use Illuminate\Console\Command;

class FirebaseMigrateFullCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:migrate:full
                            {--dry-run : Validate and log only; no database writes}
                            {--skip-media : Do not download images into Spatie media}
                            {--exports-path= : Override firebase-migration.exports_path}';

    protected $description = 'Import all data from Firestore JSON exports (categories → users → stores → listings → favorites → reviews → store_followers → search_queries → app_config)';

    public function handle(FirestoreMigrationService $migration): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $exportsPath = $this->option('exports-path');
        if (is_string($exportsPath) && $exportsPath !== '') {
            config(['firebase-migration.exports_path' => $exportsPath]);
        }

        $dry = (bool) $this->option('dry-run');
        $skipMedia = (bool) $this->option('skip-media');

        $this->info('Firebase migration (full dataset, no per-step limit)…');
        $line = fn (string $m) => $this->line($m);

        $totals = $migration->run(null, $dry, $skipMedia, $line);

        $this->table(
            ['Metric', 'Count'],
            [
                ['OK', $totals['ok']],
                ['Skipped', $totals['skip']],
                ['Errors', $totals['err']],
            ]
        );

        $this->line('Log file: '.config('firebase-migration.log_path'));

        return $totals['err'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
