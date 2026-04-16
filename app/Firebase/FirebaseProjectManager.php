<?php

namespace App\Firebase;

use Illuminate\Contracts\Container\Container;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use Kreait\Laravel\Firebase\FirebaseProject;
use Kreait\Laravel\Firebase\FirebaseProjectManager as KreaitFirebaseProjectManager;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

/**
 * Extends Kreait's manager to:
 * - resolve relative credential paths with {@see storage_path()} (not {@see base_path()}), matching
 *   the common "path relative to storage/" convention (e.g. {@code app/firebase/service-account.json});
 * - apply {@see Factory::withProjectId()} when {@code config('firebase.projects.*.project_id')} is set.
 */
class FirebaseProjectManager extends KreaitFirebaseProjectManager
{
    public function __construct(Container $app)
    {
        parent::__construct($app);
    }

    /**
     * Relative paths are resolved under {@code storage/}, not the project root (Kreait default uses {@code basePath()}).
     *
     * @param  string  $credentials  filesystem path, absolute path, or JSON string
     */
    protected function resolveJsonCredentials(string $credentials): string
    {
        $isJsonString = str_starts_with($credentials, '{');
        $isAbsoluteLinuxPath = str_starts_with($credentials, '/');
        $isAbsoluteWindowsPath = strlen($credentials) > 2
            && ctype_alpha($credentials[0])
            && $credentials[1] === ':'
            && ($credentials[2] === '\\' || $credentials[2] === '/');

        $isRelativePath = ! $isJsonString && ! $isAbsoluteLinuxPath && ! $isAbsoluteWindowsPath;

        if ($isRelativePath) {
            return storage_path($credentials);
        }

        return $credentials;
    }

    protected function configure(string $name): FirebaseProject
    {
        $factory = new Factory;

        $config = $this->configuration($name);

        if ($tenantId = $config['auth']['tenant_id'] ?? null) {
            $factory = $factory->withTenantId($tenantId);
        }

        if ($credentials = $config['credentials']['file'] ?? ($config['credentials'] ?? null)) {
            if (is_string($credentials)) {
                $credentials = $this->resolveJsonCredentials($credentials);
            }

            $factory = $factory->withServiceAccount($credentials);
        }

        if (is_string($projectId = $config['project_id'] ?? null) && $projectId !== '') {
            $factory = $factory->withProjectId($projectId);
        }

        if ($databaseUrl = $config['database']['url'] ?? null) {
            $factory = $factory->withDatabaseUri($databaseUrl);
        }

        if ($authVariableOverride = $config['database']['auth_variable_override'] ?? null) {
            $factory = $factory->withDatabaseAuthVariableOverride($authVariableOverride);
        }

        if ($firestoreDatabase = $config['firestore']['database'] ?? null) {
            $factory = $factory->withFirestoreDatabase($firestoreDatabase);
        }

        if ($defaultStorageBucket = $config['storage']['default_bucket'] ?? null) {
            $factory = $factory->withDefaultStorageBucket($defaultStorageBucket);
        }

        if ($cacheStore = $config['cache_store'] ?? null) {
            $cache = $this->app->make('cache')->store($cacheStore);

            if ($cache instanceof CacheInterface) {
                $cache = new Psr16Adapter($cache);
            } else {
                throw new InvalidArgumentException('The cache store must be an instance of a PSR-6 or PSR-16 cache');
            }

            $factory = $factory
                ->withVerifierCache($cache)
                ->withAuthTokenCache($cache);
        }

        if ($logChannel = $config['logging']['http_log_channel'] ?? null) {
            $factory = $factory->withHttpLogger(
                $this->app->make('log')->channel($logChannel)
            );
        }

        if ($logChannel = $config['logging']['http_debug_log_channel'] ?? null) {
            $factory = $factory->withHttpDebugLogger(
                $this->app->make('log')->channel($logChannel)
            );
        }

        $options = HttpClientOptions::default();

        if ($proxy = $config['http_client_options']['proxy'] ?? null) {
            $options = $options->withProxy($proxy);
        }

        if ($timeout = $config['http_client_options']['timeout'] ?? null) {
            $options = $options->withTimeOut((float) $timeout);
        }

        if ($middlewares = $config['http_client_options']['guzzle_middlewares'] ?? null) {
            $options = $options->withGuzzleMiddlewares($middlewares);
        }

        $factory = $factory->withHttpClientOptions($options);

        return new FirebaseProject($factory, $config);
    }
}
