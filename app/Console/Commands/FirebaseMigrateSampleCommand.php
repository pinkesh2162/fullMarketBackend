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

        $this->info('Firebase migration (sample, limit='.$limit.' for categories/stores/listings; users imported fully for FK mapping)…');
        $this->comment('After categories finish, the users step can run for a while (many JSON files + profile images). Remote images use a timeout (FIREBASE_MIGRATION_MEDIA_TIMEOUT); use --skip-media to skip downloads.');
        $line = function (string $m): void {
            $this->line($m);
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        };

        $totals = ['ok' => 0, 'skip' => 0, 'err' => 0];
        foreach ([
            fn () => $migration->runCategories($limit, $dry, $skipMedia, $line),
            fn () => $migration->runUsers(null, $dry, $skipMedia, $line),
            fn () => $migration->runStores($limit, $dry, $skipMedia, $line),
            fn () => $migration->runListings($limit, $dry, $skipMedia, $line),
        ] as $step) {
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
