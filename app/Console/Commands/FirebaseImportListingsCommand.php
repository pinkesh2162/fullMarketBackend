<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseImport\FirebaseImportService;
use Illuminate\Console\Command;

class FirebaseImportListingsCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:import:listings
                            {--dry-run : Count only; do not write to the database}
                            {--limit= : Max listings to import}
                            {--skip-images : Do not download Firebase Storage images (offline / faster)}';

    protected $description = 'Import Firestore listings into MySQL; downloads gallery/images into Spatie media by default';

    public function handle(FirebaseImportService $import): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $limit = $this->option('limit') !== null && $this->option('limit') !== ''
            ? max(0, (int) $this->option('limit'))
            : null;

        $r = $import->importListings(
            fn (string $m) => $this->warn($m),
            (bool) $this->option('dry-run'),
            $limit,
            (bool) $this->option('skip-images')
        );

        $rows = [
            ['Imported / would import', $r['ok']],
            ['Skipped (already in state)', $r['skip']],
            ['Errors', $r['err']],
        ];
        if (array_key_exists('missing_category', $r)) {
            $rows[] = ['Listings with null category (see logs / re-import)', $r['missing_category']];
        }
        $this->table(['Metric', 'Count'], $rows);

        return $r['err'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
