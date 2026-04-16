<?php

namespace App\Services\Firebase;

use Carbon\Carbon;
use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Firestore REST v1 client (no ext-grpc). Uses OAuth access token from the service account JSON.
 *
 * @see https://firebase.google.com/docs/firestore/reference/rest/v1/projects.databases.documents
 */
class FirestoreRestService
{
    protected ?ServiceAccountCredentials $credentials = null;

    protected ?string $projectId = null;

    public function __construct(
        protected Client $http
    ) {
        $keyPath = base_path(config('app.firebase_service_account'));
        if (is_readable($keyPath)) {
            $this->credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/datastore',
                $keyPath
            );
            $decoded = json_decode((string) file_get_contents($keyPath), true);
            $this->projectId = is_array($decoded) ? ($decoded['project_id'] ?? null) : null;
        }
        if (! $this->projectId && config('app.firebase_project_id')) {
            $this->projectId = (string) config('app.firebase_project_id');
        }
    }

    public function isConfigured(): bool
    {
        return $this->credentials !== null && $this->projectId !== null;
    }

    /**
     * @return array<string, mixed>|null Decoded document fields (PHP scalars / arrays); null if missing.
     */
    public function getDocumentFields(string $collectionId, string $documentId): ?array
    {
        $path = $this->documentPath($collectionId, $documentId);
        $res = $this->request('GET', $path);
        if ($res === null) {
            return null;
        }

        return $this->decodeFields($res['fields'] ?? null);
    }

    public function documentExists(string $collectionId, string $documentId): bool
    {
        return $this->getDocumentFields($collectionId, $documentId) !== null;
    }

    /**
     * @param  array<string, mixed>  $phpFields
     */
    public function patchDocument(string $collectionId, string $documentId, array $phpFields): void
    {
        if ($phpFields === []) {
            return;
        }
        $path = $this->documentPath($collectionId, $documentId);
        $encoded = $this->encodeFields($phpFields);
        $mask = $this->updateMaskQuery($encoded);
        $this->request('PATCH', $path.'?'.$mask, ['fields' => $encoded]);
    }

    /**
     * @param  array<string, mixed>  $phpFields
     */
    public function addDocument(string $collectionId, array $phpFields): void
    {
        $parent = sprintf(
            'projects/%s/databases/(default)/documents',
            $this->projectId
        );
        $path = $parent.'/'.rawurlencode($collectionId);
        $encoded = $this->encodeFields($phpFields);
        $this->request('POST', $path, ['fields' => $encoded]);
    }

    protected function documentPath(string $collectionId, string $documentId): string
    {
        return sprintf(
            'projects/%s/databases/(default)/documents/%s/%s',
            $this->projectId,
            rawurlencode($collectionId),
            rawurlencode($documentId)
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $encoded
     */
    protected function updateMaskQuery(array $encoded): string
    {
        $parts = [];
        foreach (array_keys($encoded) as $key) {
            $parts[] = 'updateMask.fieldPaths='.rawurlencode((string) $key);
        }

        return implode('&', $parts);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function request(string $method, string $path, ?array $json = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $token = $this->credentials->fetchAuthToken();
        if (! is_array($token) || empty($token['access_token'])) {
            return null;
        }

        $url = 'https://firestore.googleapis.com/v1/'.$path;
        $opts = [
            'headers' => [
                'Authorization' => 'Bearer '.$token['access_token'],
                'Content-Type' => 'application/json',
            ],
            'http_errors' => false,
        ];
        if ($json !== null) {
            $opts['json'] = $json;
        }

        $res = $this->http->request($method, $url, $opts);
        $code = $res->getStatusCode();
        $body = (string) $res->getBody();

        if ($code === 404) {
            return null;
        }
        if ($code < 200 || $code >= 300) {
            Log::warning('firestore_rest.request_failed', [
                'method' => $method,
                'path' => $path,
                'status' => $code,
                'body' => mb_substr($body, 0, 500),
            ]);
            throw new \RuntimeException('Firestore REST error HTTP '.$code.': '.substr($body, 0, 800));
        }

        if ($body === '') {
            return [];
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>|null  $fields
     * @return array<string, mixed>
     */
    protected function decodeFields(?array $fields): array
    {
        if ($fields === null || $fields === []) {
            return [];
        }
        $out = [];
        foreach ($fields as $k => $v) {
            if (! is_array($v)) {
                continue;
            }
            $out[$k] = $this->decodeValue($v);
        }

        return $out;
    }

    protected function decodeValue(array $v): mixed
    {
        if (array_key_exists('stringValue', $v)) {
            return (string) $v['stringValue'];
        }
        if (array_key_exists('integerValue', $v)) {
            return (int) $v['integerValue'];
        }
        if (array_key_exists('doubleValue', $v)) {
            return (float) $v['doubleValue'];
        }
        if (array_key_exists('booleanValue', $v)) {
            return (bool) $v['booleanValue'];
        }
        if (array_key_exists('nullValue', $v)) {
            return null;
        }
        if (array_key_exists('timestampValue', $v)) {
            return (string) $v['timestampValue'];
        }
        if (array_key_exists('mapValue', $v) && is_array($v['mapValue']['fields'] ?? null)) {
            return $this->decodeFields($v['mapValue']['fields']);
        }
        if (array_key_exists('arrayValue', $v)) {
            $vals = $v['arrayValue']['values'] ?? [];

            return array_map(fn ($item) => is_array($item) ? $this->decodeValue($item) : $item, is_array($vals) ? $vals : []);
        }

        return $v;
    }

    /**
     * @param  array<string, mixed>  $php
     * @return array<string, array<string, mixed>>
     */
    protected function encodeFields(array $php): array
    {
        $out = [];
        foreach ($php as $k => $val) {
            $out[(string) $k] = $this->encodeValue($val);
        }

        return $out;
    }

    protected function encodeValue(mixed $val): array
    {
        if ($val === null) {
            return ['nullValue' => null];
        }
        if (is_bool($val)) {
            return ['booleanValue' => $val];
        }
        if (is_int($val)) {
            return ['integerValue' => (string) $val];
        }
        if (is_float($val)) {
            return ['doubleValue' => $val];
        }
        if ($val instanceof \DateTimeInterface) {
            return ['timestampValue' => Carbon::parse($val)->utc()->format('Y-m-d\TH:i:s.v\Z')];
        }
        if (is_string($val)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $val)) {
                return ['timestampValue' => $this->normalizeTimestampString($val)];
            }

            return ['stringValue' => $val];
        }
        if (is_array($val)) {
            if ($val === []) {
                return ['mapValue' => ['fields' => new \stdClass]];
            }
            if (array_is_list($val)) {
                return [
                    'arrayValue' => [
                        'values' => array_map(fn ($i) => $this->encodeValue($i), $val),
                    ],
                ];
            }

            return [
                'mapValue' => [
                    'fields' => $this->encodeFields($val),
                ],
            ];
        }

        return ['stringValue' => (string) json_encode($val, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }

    protected function normalizeTimestampString(string $s): string
    {
        try {
            return Carbon::parse($s)->utc()->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable) {
            return $s;
        }
    }
}
