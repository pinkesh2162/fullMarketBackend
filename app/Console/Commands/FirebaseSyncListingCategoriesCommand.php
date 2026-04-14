<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseImport\FirebaseImportService;
use Illuminate\Console\Command;

class FirebaseSyncListingCategoriesCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:sync:listing-categories
                            {--dry-run : Show counts only; do not update the database}
                            {--limit= : Max listing JSON files to process}
                            {--only-missing : Only set category when service_category is currently null}
                            {--exports-path= : Override firebase-import.exports_path}';

    protected $description = 'Assign categories only: updates service_category from Firestore listing exports; does not change other listing data or images';

    public function handle(FirebaseImportService $import): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $exportsPath = $this->option('exports-path');
        if (is_string($exportsPath) && $exportsPath !== '') {
            config(['firebase-import.exports_path' => $exportsPath]);
        }

        $limit = $this->option('limit') !== null && $this->option('limit') !== ''
            ? max(0, (int) $this->option('limit'))
            : null;

        $r = $import->syncListingCategoriesOnly(
            fn (string $m) => $this->warn($m),
            (bool) $this->option('dry-run'),
            $limit,
            (bool) $this->option('only-missing')
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated (or would update)', $r['ok']],
                ['Unchanged (already correct)', $r['unchanged']],
                ['Skipped (no local listing)', $r['skip']],
                ['Skipped (--only-missing, already had category)', $r['skipped_had_category']],
                ['Could not resolve category from export', $r['missing_category']],
                ['Errors', $r['err']],
            ]
        );

        $this->newLine();
        $this->info('Only the service_category column is modified; other fields and media are left as-is. Unresolved exports never clear an existing category.');

        return $r['err'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
