<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EnsuresDatabaseIsReachable;
use App\Services\FirebaseImport\FirebaseImportService;
use Illuminate\Console\Command;

class FirebaseImportAllCommand extends Command
{
    use EnsuresDatabaseIsReachable;

    protected $signature = 'firebase:import:all
                            {--dry-run : Run every step in dry-run mode}
                            {--limit= : Cap how many records each step processes (also limits users — omit for full migration)}
                            {--skip-images : Pass through to listings import}
                            {--no-update-passwords : Do not set default password for existing firebase users (new users still get it)}
                            {--exports-path= : Override firebase-import.exports_path for this run}';

    protected $description = 'Run imports in order: categories → users (sets default password on firebase users) → stores → listings → favorites → app-settings';

    public function handle(FirebaseImportService $import): int
    {
        if ($this->ensureDatabaseConnection() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null && $this->option('limit') !== ''
            ? max(0, (int) $this->option('limit'))
            : null;
        $skipImages = (bool) $this->option('skip-images');

        $exportsPath = $this->option('exports-path');
        if (is_string($exportsPath) && $exportsPath !== '') {
            config(['firebase-import.exports_path' => $exportsPath]);
        }

        $log = fn (string $m) => $this->warn($m);
        $totalErr = 0;

        $this->info('1/6 Categories…');
        $r = $import->importCategories($log, $dry, $limit);
        $this->tableFrom($r);
        $totalErr += $r['err'];

        $this->info('2/6 Users…');
        $updatePasswords = ! ((bool) $this->option('no-update-passwords'));
        $r = $import->importUsers($log, $dry, $limit, $updatePasswords);
        $this->tableFrom($r);
        $totalErr += $r['err'];

        $this->info('3/6 Stores…');
        $r = $import->importStores($log, $dry, $limit);
        $this->tableFrom($r);
        $totalErr += $r['err'];

        $this->info('4/6 Listings…');
        $r = $import->importListings($log, $dry, $limit, $skipImages);
        $this->tableFrom($r);
        $totalErr += $r['err'];

        $this->info('5/6 Favorites…');
        $r = $import->importFavorites($log, $dry, $limit);
        $this->tableFrom($r);
        $totalErr += $r['err'];

        $this->info('6/6 App settings…');
        $r = $import->importAppSettings($log, $dry);
        $this->tableFrom($r);
        $totalErr += $r['err'];

        $this->info('Done.');

        return $totalErr > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{ok:int,skip:int,err:int,pwd_updated?:int,missing_category?:int}  $r
     */
    protected function tableFrom(array $r): void
    {
        $row = [$r['ok'], $r['skip'], $r['err']];
        $headers = ['ok', 'skip', 'err'];
        if (array_key_exists('pwd_updated', $r)) {
            $headers[] = 'pwd_updated';
            $row[] = $r['pwd_updated'];
        }
        if (array_key_exists('missing_category', $r)) {
            $headers[] = 'no_category';
            $row[] = $r['missing_category'];
        }
        $this->table($headers, [$row]);
    }
}
