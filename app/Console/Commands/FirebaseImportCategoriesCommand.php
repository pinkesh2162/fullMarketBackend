<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseImport\FirebaseImportService;
use Illuminate\Console\Command;

class FirebaseImportCategoriesCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:import:categories
                            {--dry-run : Count only; do not write to the database}
                            {--limit= : Max JSON files to process}';

    protected $description = 'Import Firestore `categories` JSON exports into the categories table (with images)';

    public function handle(FirebaseImportService $import): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $limit = $this->option('limit') !== null && $this->option('limit') !== ''
            ? max(0, (int) $this->option('limit'))
            : null;

        $r = $import->importCategories(
            fn (string $m) => $this->warn($m),
            (bool) $this->option('dry-run'),
            $limit
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['Imported / would import', $r['ok']],
                ['Skipped (already in state)', $r['skip']],
                ['Errors', $r['err']],
            ]
        );

        return $r['err'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
