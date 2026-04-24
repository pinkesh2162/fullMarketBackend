<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected $client;

    protected $credentials;

    protected ?string $projectId = null;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 30]);

        // Same JSON as kreait/laravel-firebase (config/firebase.php + FIREBASE_CREDENTIALS), then legacy app.firebase_service_account
        $keyPath = $this->resolveServiceAccountKeyPath();

        if ($keyPath !== null && is_file($keyPath)) {
            $this->credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/cloud-platform',
                $keyPath
            );
            $decoded = json_decode((string) file_get_contents($keyPath), true);
            $this->projectId = is_array($decoded) ? ($decoded['project_id'] ?? null) : null;
        } else {
            Log::warning('Firebase service account file not found for FcmService. Set FIREBASE_CREDENTIALS (see config/firebase.php) or FIREBASE_SERVICE_ACCOUNT / place JSON at project root.');
        }
    }

    private function resolveServiceAccountKeyPath(): ?string
    {
        $fromFirebaseConfig = config('firebase.projects.app.credentials');
        if (is_string($fromFirebaseConfig) && $fromFirebaseConfig !== '' && is_file($fromFirebaseConfig)) {
            return $fromFirebaseConfig;
        }

        $legacy = base_path((string) config('app.firebase_service_account', 'firebase_service_account.json'));
        if (is_file($legacy)) {
            return $legacy;
        }

        $rootDefault = base_path('serviceAccountKey.json');

        return is_file($rootDefault) ? $rootDefault : null;
    }

    public function isConfigured(): bool
    {
        return $this->credentials !== null && $this->projectId !== null;
    }

    public function getProjectId(): ?string
    {
        return $this->projectId;
    }

    /**
     * @return array{access_token: string}|null
     */
    protected function fetchAccessToken(): ?array
    {
        if (! $this->credentials) {
            return null;
        }

        try {
            $token = $this->credentials->fetchAuthToken();

            return is_array($token) ? $token : null;
        } catch (\Throwable $e) {
            Log::error('FCM access token error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Send FCM notification using v1 API.
     *
     * @param  string  $token
     * @param  string  $title
     * @param  string  $body
     * @param  array<string, mixed>  $data
     *
     * @throws GuzzleException
     */
    public function sendNotification($token, $title, $body, $data = []): bool
    {
        $r = $this->sendMessageV1($token, $title, $body, $data);

        return $r['ok'] === true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, http_status: ?int, fcm_status: ?string, error_message: ?string}
     */
    public function sendMessageV1(string $token, string $title, string $body, array $data = []): array
    {
        if (! $this->credentials || ! $this->projectId || $token === '') {
            return ['ok' => false, 'http_status' => null, 'fcm_status' => null, 'error_message' => 'FCM not configured or empty token.'];
        }

        $access = $this->fetchAccessToken();
        if (! is_array($access) || empty($access['access_token'])) {
            return ['ok' => false, 'http_status' => null, 'fcm_status' => null, 'error_message' => 'Unable to obtain access token.'];
        }

        $url = 'https://fcm.googleapis.com/v1/projects/'.$this->projectId.'/messages:send';

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
            ],
        ];

        if (! empty($data)) {
            $payload['message']['data'] = array_map('strval', $data);
        }

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$access['access_token'],
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $code = $response->getStatusCode();

            return [
                'ok' => $code === 200,
                'http_status' => $code,
                'fcm_status' => null,
                'error_message' => $code === 200 ? null : 'Unexpected HTTP '.$code,
            ];
        } catch (RequestException $e) {
            return $this->mapFcmRequestException($e);
        } catch (\Throwable $e) {
            Log::error('FCM Send Error: '.$e->getMessage());

            return ['ok' => false, 'http_status' => null, 'fcm_status' => null, 'error_message' => $e->getMessage()];
        }
    }

    /**
     * @param  list<array{token: string, user_id: int}>  $items
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, clear_user_ids: list<int>}
     */
    public function sendManyConcurrent(array $items, string $title, string $body, array $data, int $concurrency): array
    {
        $sent = 0;
        $failed = 0;
        $clearUserIds = [];

        if ($items === [] || ! $this->isConfigured()) {
            return ['sent' => 0, 'failed' => count($items), 'clear_user_ids' => []];
        }

        $access = $this->fetchAccessToken();
        if (! is_array($access) || empty($access['access_token'])) {
            return ['sent' => 0, 'failed' => count($items), 'clear_user_ids' => []];
        }

        $accessToken = $access['access_token'];
        $url = 'https://fcm.googleapis.com/v1/projects/'.$this->projectId.'/messages:send';

        $requests = function () use ($items, $accessToken, $url, $title, $body, $data) {
            foreach ($items as $idx => $row) {
                $token = $row['token'] ?? '';
                if ($token === '') {
                    continue;
                }

                $payload = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                    ],
                ];
                if (! empty($data)) {
                    $payload['message']['data'] = array_map('strval', $data);
                }

                yield $idx => function () use ($url, $accessToken, $payload) {
                    return $this->client->postAsync($url, [
                        'headers' => [
                            'Authorization' => 'Bearer '.$accessToken,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => $payload,
                    ]);
                };
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => function ($response, $idx) use (&$sent, &$failed) {
                $code = $response->getStatusCode();
                if ($code === 200) {
                    $sent++;
                } else {
                    $failed++;
                }
            },
            'rejected' => function ($reason, $idx) use (&$sent, &$failed, $items, &$clearUserIds) {
                $failed++;
                $fcmStatus = null;
                if ($reason instanceof RequestException && $reason->hasResponse()) {
                    $parsed = $this->parseFcmErrorBody((string) $reason->getResponse()->getBody());
                    $fcmStatus = $parsed['status'] ?? null;
                }
                if (in_array($fcmStatus, ['UNREGISTERED', 'NOT_FOUND'], true)) {
                    $userId = $items[$idx]['user_id'] ?? null;
                    if (is_int($userId) && $userId > 0) {
                        $clearUserIds[] = $userId;
                    }
                }
            },
        ]);

        $pool->promise()->wait();

        return [
            'sent' => $sent,
            'failed' => $failed,
            'clear_user_ids' => array_values(array_unique($clearUserIds)),
        ];
    }

    /**
     * @return array{ok: bool, http_status: ?int, fcm_status: ?string, error_message: ?string}
     */
    protected function mapFcmRequestException(RequestException $e): array
    {
        $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
        $parsed = $e->hasResponse() ? $this->parseFcmErrorBody((string) $e->getResponse()->getBody()) : ['status' => null, 'message' => $e->getMessage()];

        return [
            'ok' => false,
            'http_status' => $status,
            'fcm_status' => $parsed['status'] ?? null,
            'error_message' => $parsed['message'] ?? $e->getMessage(),
        ];
    }

    /**
     * @return array{status: ?string, message: ?string}
     */
    protected function parseFcmErrorBody(string $body): array
    {
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['status' => null, 'message' => $body !== '' ? $body : null];
        }

        $err = is_array($json) && isset($json['error']) && is_array($json['error']) ? $json['error'] : [];

        return [
            'status' => isset($err['status']) ? (string) $err['status'] : null,
            'message' => isset($err['message']) ? (string) $err['message'] : null,
        ];
    }
}
