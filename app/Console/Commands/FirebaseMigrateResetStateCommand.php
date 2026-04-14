<?php

namespace App\Console\Commands;

use App\Services\FirebaseMigration\MigrationState;
use Illuminate\Console\Command;

class FirebaseMigrateResetStateCommand extends Command
{
    protected $signature = 'firebase:migrate:reset-state {--force : Skip confirmation}';

    protected $description = 'Delete the firebase-migration-state.json mapping file (does not delete MySQL rows)';

    public function handle(MigrationState $state): int
    {
        if (! $this->option('force') && ! $this->confirm('Delete migration state file?')) {
            return Command::SUCCESS;
        }

        $state->reset();
        $this->info('Migration state cleared: '.config('firebase-migration.state_path'));

        return Command::SUCCESS;
    }
}
