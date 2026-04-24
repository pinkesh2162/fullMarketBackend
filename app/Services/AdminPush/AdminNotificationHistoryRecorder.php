<?php

namespace App\Services\AdminPush;

use App\Models\AdminNotificationCampaign;
use Illuminate\Support\Facades\Log;
use stdClass;

class AdminNotificationHistoryRecorder
{
    /**
     * Create or update the initial row when a campaign is persisted to Firestore (processing / queued).
     */
    public function recordStart(
        string $campaignId,
        string $title,
        string $body,
        string $segmentId,
        string $segmentLabel,
        string $countryFilter,
        string $cityFilter,
        int $targetedUsers,
        int $reachableDevices,
        int $skippedNoToken,
        string $status,
        stdClass $adminClaims,
        ?int $actorUserId,
        string $source,
        bool $dryRun = false
    ): void {
        if ($dryRun) {
            return;
        }

        try {
            $model = AdminNotificationCampaign::query()->firstOrNew(['campaign_id' => $campaignId]);
            if (! $model->exists) {
                $model->public_id = $this->uniquePublicId();
            }
            $model->title = $title;
            $model->body = $body;
            $model->selected_segment = $segmentId;
            $model->segment_label = $segmentLabel;
            $model->country_filter = $countryFilter === 'all' ? null : $countryFilter;
            $model->city_filter = $cityFilter === 'all' ? null : $cityFilter;
            $model->targeted_users = $targetedUsers;
            $model->reachable_devices = $reachableDevices;
            $model->skipped_no_token = $skippedNoToken;
            $model->status = $status;
            $model->sent_count = 0;
            $model->failed_count = 0;
            $model->source = $source;
            $model->dry_run = false;
            $model->created_by_user_id = $actorUserId;
            $model->created_by_email = (string) ($adminClaims->email ?? '') ?: null;
            $model->error_message = null;
            if ($status === AdminNotificationCampaign::STATUS_QUEUED) {
                $model->sent_at = null;
            }
            $model->save();
        } catch (\Throwable $e) {
            Log::error('admin_push.history_start_failed', ['message' => $e->getMessage(), 'campaign_id' => $campaignId]);
        }
    }

    /**
     * Update counts and terminal status when FCM work finishes.
     */
    public function recordComplete(
        string $campaignId,
        int $sent,
        int $failed,
        int $skippedNoToken,
        string $status,
        ?string $errorMessage
    ): void {
        try {
            $model = AdminNotificationCampaign::query()->where('campaign_id', $campaignId)->first();
            if ($model === null) {
                return;
            }
            $model->sent_count = $sent;
            $model->failed_count = $failed;
            $model->skipped_no_token = $skippedNoToken;
            $model->status = $status;
            $model->error_message = $errorMessage;
            if (in_array($status, [
                AdminNotificationCampaign::STATUS_SENT,
                AdminNotificationCampaign::STATUS_FAILED,
                AdminNotificationCampaign::STATUS_PARTIALLY_SENT,
            ], true)) {
                $model->sent_at = now();
            }
            $model->save();
        } catch (\Throwable $e) {
            Log::error('admin_push.history_complete_failed', ['message' => $e->getMessage(), 'campaign_id' => $campaignId]);
        }
    }

    private function uniquePublicId(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $id = AdminNotificationCampaign::generatePublicId();
            if (! AdminNotificationCampaign::query()->where('public_id', $id)->exists()) {
                return $id;
            }
        }

        return AdminNotificationCampaign::generatePublicId();
    }
}
