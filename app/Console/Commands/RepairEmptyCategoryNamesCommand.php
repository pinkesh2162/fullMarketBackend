<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RepairEmptyCategoryNamesCommand extends Command
{
    protected $signature = 'categories:repair-empty-names
                            {--dry-run : Show how many rows would be updated}';

    protected $description = 'Fix category names from Firestore export/state mapping (no placeholder guesses)';

    public function handle(): int
    {
        $statePath = (string) config('firebase-migration.state_path');
        $exportsRoot = (string) config('firebase-migration.exports_path');
        $categoryDir = rtrim($exportsRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'categories';

        $state = $this->readJsonFile($statePath);
        if (! is_array($state)) {
            $this->error('Migration state file missing or invalid: '.$statePath);

            return self::FAILURE;
        }
        $categoryMap = $state['categories'] ?? null;
        if (! is_array($categoryMap) || $categoryMap === []) {
            $this->error('No category mappings found in migration state.');

            return self::FAILURE;
        }
        if (! is_dir($categoryDir)) {
            $this->error('Categories export folder not found: '.$categoryDir);

            return self::FAILURE;
        }

        // local category id => firestore doc id
        $localToFirebase = [];
        foreach ($categoryMap as $firebaseId => $localId) {
            if (! is_scalar($localId) || ! is_string($firebaseId) || trim($firebaseId) === '') {
                continue;
            }
            $localToFirebase[(int) $localId] = $firebaseId;
        }

        $query = Category::query()->where(function ($q): void {
            $q->whereRaw("TRIM(COALESCE(name, '')) = ''")
                ->orWhere('name', 'like', 'Imported #%');
        });
        $count = (clone $query)->count();
        if ($count === 0) {
            $this->info('No empty/placeholder category names found.');

            return self::SUCCESS;
        }

        $missingMap = 0;
        $missingDoc = 0;
        $missingName = 0;
        $wouldUpdate = 0;
        $updated = 0;

        $query->orderBy('id')->chunkById(200, function ($categories) use (
            &$missingMap,
            &$missingDoc,
            &$missingName,
            &$wouldUpdate,
            &$updated,
            $localToFirebase,
            $categoryDir
        ) {
            foreach ($categories as $cat) {
                $currentName = trim((string) $cat->name);
                if ($currentName !== '' && ! str_starts_with($currentName, 'Imported #')) {
                    continue;
                }

                $firebaseId = $localToFirebase[(int) $cat->id] ?? null;
                if ($firebaseId === null) {
                    $missingMap++;
                    continue;
                }

                $docPath = $categoryDir.DIRECTORY_SEPARATOR.$firebaseId.'.json';
                $doc = $this->readJsonFile($docPath);
                if (! is_array($doc)) {
                    $missingDoc++;
                    continue;
                }

                $data = $doc['data'] ?? null;
                if (! is_array($data)) {
                    $missingDoc++;
                    continue;
                }

                $name = $this->firebaseCategoryName($data);
                if ($name === null) {
                    $missingName++;
                    continue;
                }

                $wouldUpdate++;
                if (! $this->option('dry-run')) {
                    $cat->update(['name' => $name]);
                    $updated++;
                }
            }
        });

        if ($this->option('dry-run')) {
            $this->info("Would update {$wouldUpdate} categories from Firestore names.");
            $this->line("Skipped (no map): {$missingMap}");
            $this->line("Skipped (missing export doc): {$missingDoc}");
            $this->line("Skipped (no valid name in export): {$missingName}");

            return self::SUCCESS;
        }
        $this->info("Updated {$updated} categories from Firestore names.");
        $this->line("Skipped (no map): {$missingMap}");
        $this->line("Skipped (missing export doc): {$missingDoc}");
        $this->line("Skipped (no valid name in export): {$missingName}");

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function readJsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    protected function firebaseCategoryName(array $data): ?string
    {
        foreach (['name', 'title', 'label'] as $key) {
            $value = $data[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }
            $name = trim($value);
            if ($name === '') {
                continue;
            }

            return Str::limit($name, 255, '');
        }

        return null;
    }
}
