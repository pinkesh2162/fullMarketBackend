<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

trait EnsuresDatabaseIsReachable
{
    /**
     * Verify PDO can connect before running imports (avoids silent per-row errors).
     */
    protected function ensureDatabaseConnection(): int
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Cannot connect to the database.');
            $this->line('  '.$e->getMessage());
            $this->newLine();
            $this->line('Check your .env values and that MySQL is running.');
            $this->line('For MAMP on macOS, typical fixes:');
            $this->line('  • Start MySQL from the MAMP window (Open WebStart / Start servers).');
            $this->line('  • DB_HOST=127.0.0.1  (using "localhost" can use a wrong Unix socket)');
            $this->line('  • DB_PORT= the port from MAMP → Preferences → Ports (MySQL is often 8889, sometimes 3306).');
            $this->line('  • DB_DATABASE, DB_USERNAME, DB_PASSWORD match the MAMP MySQL user you created.');
            $this->newLine();

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
