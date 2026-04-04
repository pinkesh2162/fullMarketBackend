<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Services\FcmService;
use Illuminate\Support\Facades\Log;

class SendFcmNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    protected $token;
    protected $title;
    protected $body;
    protected $data;

    /**
     * Create a new job instance.
     */
    public function __construct($token, $title, $body, $data = [], $userId = null)
    {
        $this->token = $token;
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(FcmService $fcmService): void
    {
        // 1. Identify the user
        $user = null;
        if ($this->userId) {
            $user = \App\Models\User::with('settings')->find($this->userId);
        } elseif ($this->token) {
            $user = \App\Models\User::where('fcm_token', $this->token)->with('settings')->first();
        }

        // 2. Store notification in database if user is found
        if ($user) {
            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title'   => $this->title,
                'body'    => $this->body,
                'data'    => $this->data,
            ]);
        }

        if (!$this->token) {
            return;
        }

        // 3. Handle FCM Push notification with custom window logic
        $settings = $user ? $user->settings : null;

        if ($settings && $settings->notification_time_start && $settings->notification_time_end) {
            $now = now();
            // Parse time strings
            $start = \Carbon\Carbon::createFromFormat('H:i:s', $settings->notification_time_start)->setDateFrom($now);
            $end = \Carbon\Carbon::createFromFormat('H:i:s', $settings->notification_time_end)->setDateFrom($now);

            // Handle overnight windows (e.g. 22:00 to 06:00)
            if ($start->greaterThan($end)) {
                if ($now->between($end, $start)) {
                    Log::info("FCM notification skipped for user [{$user->id}]. Outside custom window {$settings->notification_time_start} - {$settings->notification_time_end}.");
                    return;
                }
            } else {
                if (!$now->between($start, $end)) {
                    Log::info("FCM notification skipped for user [{$user->id}]. Outside custom window {$settings->notification_time_start} - {$settings->notification_time_end}.");
                    return;
                }
            }
        }

        try {
            $fcmService->sendNotification($this->token, $this->title, $this->body, $this->data);
        } catch (\Exception $e) {
            Log::error("Failed to send FCM notification via Job: " . $e->getMessage());
        }
    }
}
