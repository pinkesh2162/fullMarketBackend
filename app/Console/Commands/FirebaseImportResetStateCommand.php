<?php

namespace App\Console\Commands;

use App\Services\FirebaseImport\ImportState;
use Illuminate\Console\Command;

class FirebaseImportResetStateCommand extends Command
{
    protected $signature = 'firebase:import:reset-state {--force : Skip confirmation}';

    protected $description = 'Delete firebase-import-state.json so the next import can remap Firestore IDs (does not delete MySQL rows)';

    public function handle(ImportState $state): int
    {
        $path = config('firebase-import.state_path');
        if (! is_file($path)) {
            $this->info('No state file at: '.$path);

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Remove '.$path.'?', false)) {
            return Command::SUCCESS;
        }

        $state->reset();
        $this->info('Import state cleared.');

        return Command::SUCCESS;
    }
}
