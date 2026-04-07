<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseImport\FirebaseImportService;
use Illuminate\Console\Command;

class FirebaseImportUsersCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:import:users
                            {--dry-run : Count only; do not write to the database}
                            {--limit= : Max users to create}
                            {--update-passwords : Set default password for existing firebase users too}
                            {--exports-path= : Override config firebase-import.exports_path for this run}';

    protected $description = 'Import users from users/, favorites, listings, and admins Firestore exports';

    public function handle(FirebaseImportService $import): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $limit = $this->option('limit') !== null && $this->option('limit') !== ''
            ? max(0, (int) $this->option('limit'))
            : null;

        $exportsPath = $this->option('exports-path');
        if (is_string($exportsPath) && $exportsPath !== '') {
            config(['firebase-import.exports_path' => $exportsPath]);
        }

        $r = $import->importUsers(
            fn (string $m) => $this->warn($m),
            (bool) $this->option('dry-run'),
            $limit,
            (bool) $this->option('update-passwords')
        );

        $rows = [
            ['Created / would create', $r['ok']],
            ['Skipped (already exists)', $r['skip']],
            ['Passwords updated (existing)', $r['pwd_updated']],
            ['Errors', $r['err']],
        ];
        $this->table(['Metric', 'Count'], $rows);

        $this->newLine();
        $this->line('Export root: '.$r['exports_root']);
        if (! $r['exports_root_exists']) {
            $this->warn('This path is not a directory. Set FIREBASE_EXPORTS_PATH in .env or pass --exports-path=/path/to/firestore');
        }
        $src = $r['source_json_files'];
        $this->line(sprintf(
            'JSON files per folder: favorites=%d listings=%d admins=%d users=%d',
            $src['favorites'],
            $src['listings'],
            $src['admins'],
            $src['users']
        ));

        if ($r['profiles_count'] === 0) {
            $this->newLine();
            $this->warn('No user profiles were built. The importer expects a Firestore export with subfolders (favorites, listings, admins, users) each containing one .json file per document, or point --exports-path at the folder that contains those subfolders.');
        }

        return $r['err'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
