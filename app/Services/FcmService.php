<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected $client;
    protected $credentials;

    public function __construct()
    {
        $this->client = new Client();
        $keyPath = base_path(config('app.firebase_service_account'));

        if (file_exists($keyPath)) {
            $this->credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/cloud-platform',
                $keyPath
            );
        } else {
            Log::warning("Firebase service account file not found at: {$keyPath}. Push notifications will not be sent.");
        }
    }

    /**
     * Send FCM notification using v1 API.
     *
     * @param  string  $token
     * @param  string  $title
     * @param  string  $body
     * @param  array  $data
     * @throws GuzzleException
     * @return bool
     */
    public function sendNotification($token, $title, $body, $data = [])
    {
        if (!$this->credentials || !$token) {
            return false;
        }

        try {
            $accessTokenResponse = $this->credentials->fetchAuthToken();
            $accessToken = $accessTokenResponse['access_token'];

            // $keyPath = base_path(env('FIREBASE_SERVICE_ACCOUNT', 'fullmarket__firebase_service_account.json'));
            $keyPath = base_path(config('app.firebase_service_account'));
            $projectId = json_decode(file_get_contents($keyPath), true)['project_id'];

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                ],
            ];

            if (!empty($data)) {
                $payload['message']['data'] = array_map('strval', $data);
            }

            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            Log::error("FCM Send Error: " . $e->getMessage());
            return false;
        }
    }
}
