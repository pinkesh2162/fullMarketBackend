<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseImport\FirebaseImportService;
use Illuminate\Console\Command;

class FirebaseImportAppSettingsCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:import:app-settings {--dry-run : Show only; do not update app_settings}';

    protected $description = 'Map app_config/version_and_maintenance.json into the app_settings row';

    public function handle(FirebaseImportService $import): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $r = $import->importAppSettings(
            fn (string $m) => $this->warn($m),
            (bool) $this->option('dry-run')
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated / would update', $r['ok']],
                ['Skipped', $r['skip']],
                ['Errors', $r['err']],
            ]
        );

        return $r['err'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
