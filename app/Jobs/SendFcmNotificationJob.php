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

        // Only send between 10 AM and 5 PM
        $now = now();
        $startTime = $now->copy()->setTime(10, 0, 0);
        $endTime = $now->copy()->setTime(17, 0, 0);

        if (!$now->between($startTime, $endTime)) {
            Log::info("FCM notification skipped. Current time {$now->format('H:i')} is outside 10 AM - 5 PM window.");
            return;
        }

        try {
            $fcmService->sendNotification($this->token, $this->title, $this->body, $this->data);
        } catch (\Exception $e) {
            Log::error("Failed to send FCM notification via Job: " . $e->getMessage());
        }
    }
}
