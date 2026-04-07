<?php

namespace App\Providers;

use App\Services\FirebaseImport\FirebaseImportService;
use App\Services\FirebaseImport\ImportState;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImportState::class, function () {
            return new ImportState(config('firebase-import.state_path'));
        });

        $this->app->singleton(FirebaseImportService::class, function ($app) {
            return new FirebaseImportService($app->make(ImportState::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Json::encodeUsing(static function (mixed $value): string {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        });
    }
}
