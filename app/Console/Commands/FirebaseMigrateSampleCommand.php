<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseMigration\FirestoreMigrationService;
use Illuminate\Console\Command;

class FirebaseMigrateSampleCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:migrate:sample
                            {--dry-run : Validate and log only; no database writes}
                            {--skip-media : Do not download images into Spatie media}
                            {--exports-path= : Override firebase-migration.exports_path}';

    protected $description = 'Import a small sample (25 records per collection) from Firestore JSON exports';

    public function handle(FirestoreMigrationService $migration): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $exportsPath = $this->option('exports-path');
        if (is_string($exportsPath) && $exportsPath !== '') {
            config(['firebase-migration.exports_path' => $exportsPath]);
        }

        $limit = (int) config('firebase-migration.sample_limit', 25);
        $dry = (bool) $this->option('dry-run');
        $skipMedia = (bool) $this->option('skip-media');

        $this->info('Firebase migration (sample, limit='.$limit.' per step)…');
        $line = fn (string $m) => $this->line($m);

        $totals = $migration->run($limit, $dry, $skipMedia, $line);

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
