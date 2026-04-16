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
                            {--only-favorites : Run only favorites migration (expects users/listings already imported)}
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
        $onlyFavorites = (bool) $this->option('only-favorites');

        $this->info('Firebase migration (sample, limit='.$limit.' for categories/stores/listings/favorites; users imported fully for FK mapping)…');
        $line = fn (string $m) => $this->line($m);

        $totals = ['ok' => 0, 'skip' => 0, 'err' => 0];
        $steps = $onlyFavorites
            ? [fn () => $migration->runFavorites($limit, $dry, $skipMedia, $line)]
            : [
                fn () => $migration->runCategories($limit, $dry, $skipMedia, $line),
                fn () => $migration->runUsers(null, $dry, $skipMedia, $line),
                fn () => $migration->runStores($limit, $dry, $skipMedia, $line),
                fn () => $migration->runListings($limit, $dry, $skipMedia, $line),
                fn () => $migration->runFavorites($limit, $dry, $skipMedia, $line),
            ];

        if ($onlyFavorites) {
            $this->warn('Running favorites only. Make sure users and listings are already migrated in DB.');
        }

        foreach ($steps as $step) {
            $r = $step();
            $totals['ok'] += $r['ok'];
            $totals['skip'] += $r['skip'];
            $totals['err'] += $r['err'];
        }

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
