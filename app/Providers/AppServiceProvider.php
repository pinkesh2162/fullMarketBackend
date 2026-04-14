<?php

namespace App\Providers;

use App\Services\FirebaseMigration\CategoryReferenceResolver;
use App\Services\FirebaseMigration\FirestoreExportReader;
use App\Services\FirebaseMigration\FirestoreMigrationService;
use App\Services\FirebaseMigration\MediaImportHelper;
use App\Services\FirebaseMigration\MigrationLogger;
use App\Services\FirebaseMigration\MigrationState;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MigrationState::class, function () {
            return new MigrationState(config('firebase-migration.state_path'));
        });

        $this->app->singleton(MigrationLogger::class, function () {
            return new MigrationLogger(config('firebase-migration.log_path'));
        });

        $this->app->singleton(FirestoreExportReader::class, function () {
            return new FirestoreExportReader(
                rtrim(config('firebase-migration.exports_path'), DIRECTORY_SEPARATOR),
                (int) config('firebase-migration.json_decode_max_depth', 512)
            );
        });

        $this->app->singleton(CategoryReferenceResolver::class, function ($app) {
            return new CategoryReferenceResolver($app->make(MigrationState::class));
        });

        $this->app->singleton(MediaImportHelper::class, function () {
            return new MediaImportHelper(
                (int) config('firebase-migration.media_download_retries', 3),
                (int) config('firebase-migration.media_retry_sleep_ms', 250)
            );
        });

        $this->app->singleton(FirestoreMigrationService::class, function ($app) {
            return new FirestoreMigrationService(
                $app->make(MigrationState::class),
                $app->make(MigrationLogger::class),
                $app->make(FirestoreExportReader::class),
                $app->make(CategoryReferenceResolver::class),
                $app->make(MediaImportHelper::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
            'user' => \App\Models\User::class,
            'store' => \App\Models\Store::class,
        ]);

        Json::encodeUsing(static function (mixed $value): string {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        });
    }
}
