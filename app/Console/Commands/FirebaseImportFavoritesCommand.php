<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseImport\FirebaseImportService;
use Illuminate\Console\Command;

class FirebaseImportFavoritesCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:import:favorites
                            {--dry-run : Count only; do not write to the database}
                            {--limit= : Max favorite documents to process}';

    protected $description = 'Import Firestore favorites (listing bookmarks) after users and listings exist';

    public function handle(FirebaseImportService $import): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $limit = $this->option('limit') !== null && $this->option('limit') !== ''
            ? max(0, (int) $this->option('limit'))
            : null;

        $r = $import->importFavorites(
            fn (string $m) => $this->warn($m),
            (bool) $this->option('dry-run'),
            $limit
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['Created / would create', $r['ok']],
                ['Skipped', $r['skip']],
                ['Errors', $r['err']],
            ]
        );

        return $r['err'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
