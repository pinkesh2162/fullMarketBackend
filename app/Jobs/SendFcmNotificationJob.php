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

    protected $token;
    protected $title;
    protected $body;
    protected $data;

    /**
     * Create a new job instance.
     */
    public function __construct($token, $title, $body, $data = [])
    {
        $this->token = $token;
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(FcmService $fcmService): void
    {
        if (!$this->token) {
            return;
        }

        $user = \App\Models\User::where('fcm_token', $this->token)->with('settings')->first();
        $settings = $user ? $user->settings : null;

        if ($settings && $settings->notification_time_start && $settings->notification_time_end) {
            $now = now();
            // Parse time strings
            $start = \Carbon\Carbon::createFromFormat('H:i:s', $settings->notification_time_start)->setDateFrom($now);
            $end = \Carbon\Carbon::createFromFormat('H:i:s', $settings->notification_time_end)->setDateFrom($now);

            // Handle overnight windows (e.g. 22:00 to 06:00)
            if ($start->greaterThan($end)) {
                // If now is before end time, it means we're in the early morning part of the window (add a day to end for comparison is tricky, better to check if it's NOT between end and start)
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
