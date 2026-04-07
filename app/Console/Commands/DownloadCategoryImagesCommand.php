<?php

namespace App\Console\Commands;

use App\Services\CategoryImageStorageService;
use Illuminate\Console\Command;

class DownloadCategoryImagesCommand extends Command
{
    protected $signature = 'categories:download-images
                            {--force : Overwrite existing files}';

    protected $description = 'Download configured category icon PNGs into public/images/categories (one-time or refresh)';

    public function handle(CategoryImageStorageService $storage): int
    {
        $force = (bool) $this->option('force');
        $map = config('categories.images_by_name', []);

        $this->info('Downloading category images…');
        $downloaded = 0;
        $skipped = 0;

        foreach ($map as $key => $url) {
            try {
                $did = $storage->downloadKeyToPublic((string) $key, (string) $url, $force);
                if ($did) {
                    $this->line('  '.$key.' → '.$storage->relativePathForKey((string) $key));
                    $downloaded++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->error("  {$key}: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        foreach (
            [
                ['default', config('categories.default_image_url')],
                ['custom', config('categories.custom_category_image_url')],
            ] as [$specialKey, $url]
        ) {
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                $this->warn("  Skipping {$specialKey}: invalid URL in config.");

                continue;
            }
            try {
                $did = $storage->downloadKeyToPublic((string) $specialKey, $url, $force);
                if ($did) {
                    $this->line('  ['.$specialKey.'] → '.$storage->relativePathForKey((string) $specialKey));
                    $downloaded++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->error("  [{$specialKey}]: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info("Done. Downloaded: {$downloaded}, skipped (already present): {$skipped}.");

        return self::SUCCESS;
    }
}
