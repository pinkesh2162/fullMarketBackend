<?php

namespace App\Providers;

use App\Firebase\FirebaseProjectManager;
use App\Models\Store;
use App\Models\User;
use App\Services\AdminPush\AdminNotificationHistoryRecorder;
use App\Services\AdminPush\AdminPushBroadcastService;
use App\Services\AdminPush\AdminPushFirestoreWriter;
use App\Services\AdminPush\UserSegmentQuery;
use App\Services\FcmService;
use App\Services\Firebase\FirebaseAdminAuthorizer;
use App\Services\Firebase\FirebaseIdTokenVerifier;
use App\Services\Firebase\FirestoreRestService;
use App\Services\FirebaseMigration\CategoryReferenceResolver;
use App\Services\FirebaseMigration\FirestoreExportReader;
use App\Services\FirebaseMigration\FirestoreMigrationService;
use App\Services\FirebaseMigration\MediaImportHelper;
use App\Services\FirebaseMigration\MigrationLogger;
use App\Services\FirebaseMigration\MigrationState;
use GuzzleHttp\Client;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Kreait\Laravel\Firebase\FirebaseProjectManager as KreaitFirebaseProjectManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KreaitFirebaseProjectManager::class, function ($app) {
            return new FirebaseProjectManager($app);
        });

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

        $this->app->singleton(FirebaseIdTokenVerifier::class, function () {
            $keyPath = base_path(config('app.firebase_service_account'));
            $projectId = '';
            if (is_readable($keyPath)) {
                $json = json_decode((string) file_get_contents($keyPath), true);
                if (is_array($json) && ! empty($json['project_id'])) {
                    $projectId = (string) $json['project_id'];
                }
            }
            if ($projectId === '') {
                $projectId = (string) (config('app.firebase_project_id') ?? '');
            }

            return new FirebaseIdTokenVerifier(new Client(['timeout' => 15]), $projectId);
        });

        $this->app->singleton(FirestoreRestService::class, function () {
            return new FirestoreRestService(new Client(['timeout' => 30]));
        });

        $this->app->singleton(FirebaseAdminAuthorizer::class, function ($app) {
            return new FirebaseAdminAuthorizer(
                $app->make(FirestoreRestService::class),
                (string) config('admin_push.collection_admins')
            );
        });

        $this->app->singleton(AdminPushFirestoreWriter::class, function ($app) {
            return new AdminPushFirestoreWriter(
                $app->make(FirestoreRestService::class),
                (string) config('admin_push.collection_campaigns'),
                (string) config('admin_push.collection_admin_actions')
            );
        });

        $this->app->singleton(UserSegmentQuery::class, fn () => new UserSegmentQuery);

        $this->app->singleton(AdminNotificationHistoryRecorder::class, function ($app) {
            return new AdminNotificationHistoryRecorder;
        });

        $this->app->singleton(AdminPushBroadcastService::class, function ($app) {
            return new AdminPushBroadcastService(
                $app->make(FcmService::class),
                $app->make(AdminPushFirestoreWriter::class),
                $app->make(UserSegmentQuery::class),
                $app->make(AdminNotificationHistoryRecorder::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'user' => User::class,
            'store' => Store::class,
        ]);

        Json::encodeUsing(static function (mixed $value): string {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        });
    }
}
