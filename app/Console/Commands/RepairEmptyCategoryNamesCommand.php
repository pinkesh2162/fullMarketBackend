<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;

class RepairEmptyCategoryNamesCommand extends Command
{
    protected $signature = 'categories:repair-empty-names
                            {--dry-run : Show how many rows would be updated}';

    protected $description = 'Fix categories with blank names (e.g. after Firestore exported name: "") — sets name to "Imported #<id>"';

    public function handle(): int
    {
        $query = Category::query()->whereRaw("TRIM(COALESCE(name, '')) = ''");

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->info('No categories with empty names.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would set a name on {$count} categories.");

            return self::SUCCESS;
        }

        $updated = 0;
        $query->orderBy('id')->chunkById(200, function ($categories) use (&$updated) {
            foreach ($categories as $cat) {
                if (trim((string) $cat->name) !== '') {
                    continue;
                }
                $cat->update(['name' => 'Imported #'.$cat->id]);
                $updated++;
            }
        });

        $this->info("Updated {$updated} categories.");

        return self::SUCCESS;
    }
}
