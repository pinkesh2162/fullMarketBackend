<?php

namespace App\Services\AdminPush;

use App\Models\AdminNotificationCampaign;
use App\Models\User;
use App\Services\FcmService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;

class AdminPushBroadcastService
{
    public function __construct(
        protected FcmService $fcm,
        protected AdminPushFirestoreWriter $firestore,
        protected UserSegmentQuery $segments,
        protected AdminNotificationHistoryRecorder $history
    ) {}

    /**
     * @param  array<string, mixed>  $input  Validated request payload
     * @return array{http_status: int, payload: array<string, mixed>}
     */
    public function handle(array $input, stdClass $adminClaims, ?int $actorUserId = null, string $source = 'firebase_token'): array
    {
        set_time_limit(0);

        $dryRun = filter_var($input['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $title = (string) $input['title'];
        $body = (string) $input['body'];
        $segmentId = (string) $input['segmentId'];
        $countryFilter = (string) ($input['countryFilter'] ?? 'all');
        $cityFilter = (string) ($input['cityFilter'] ?? 'all');
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $campaignId = $this->normalizeCampaignId($input['campaignId'] ?? null);

        $scheduledAt = $input['scheduledAt'] ?? null;
        $scheduledFuture = false;
        $scheduledCarbon = null;
        if ($scheduledAt !== null && $scheduledAt !== '' && $scheduledAt !== 'null') {
            try {
                $scheduledCarbon = Carbon::parse($scheduledAt);
                $scheduledFuture = $scheduledCarbon->isFuture();
            } catch (\Throwable) {
                return [
                    'http_status' => 422,
                    'payload' => [
                        'ok' => false,
                        'code' => 'invalid_schedule',
                        'message' => 'scheduledAt could not be parsed.',
                    ],
                ];
            }
        }

        if (! $dryRun && ! $this->fcm->isConfigured()) {
            return [
                'http_status' => 503,
                'payload' => [
                    'ok' => false,
                    'code' => 'fcm_unavailable',
                    'message' => 'FCM is not configured (missing or invalid Firebase service account).',
                ],
            ];
        }

        $existing = $this->firestore->isAvailable() ? $this->firestore->getCampaignData($campaignId) : null;
        if (is_array($existing) && ($existing['status'] ?? '') === 'sent') {
            return [
                'http_status' => 200,
                'payload' => $this->summaryFromCampaignDoc($campaignId, $existing),
            ];
        }
        if (is_array($existing) && ($existing['status'] ?? '') === 'processing' && ! $this->isStaleProcessing($existing)) {
            return [
                'http_status' => 409,
                'payload' => [
                    'ok' => false,
                    'code' => 'campaign_in_progress',
                    'message' => 'This campaign is already processing. Retry later or use a new campaignId.',
                ],
            ];
        }

        $filters = [
            'segmentId' => $segmentId,
            'countryFilter' => $countryFilter,
            'cityFilter' => $cityFilter,
        ];

        $base = $this->segments->baseQuery($filters);
        $targetedUsers = (int) (clone $base)->count();
        $reachableQuery = (clone $base)->whereNotNull('fcm_token')->where('fcm_token', '!=', '');
        $reachableDevices = (int) $reachableQuery->count();
        $skippedNoToken = max(0, $targetedUsers - $reachableDevices);

        $createdBy = (string) ($adminClaims->email ?? $adminClaims->sub ?? 'unknown');
        $segmentLabel = UserSegmentQuery::segmentLabel($segmentId);

        if ($dryRun) {
            return [
                'http_status' => 200,
                'payload' => [
                    'ok' => true,
                    'campaignId' => $campaignId,
                    'targetedUsers' => $targetedUsers,
                    'reachableDevices' => $reachableDevices,
                    'sent' => 0,
                    'failed' => 0,
                    'skippedNoToken' => $skippedNoToken,
                    'status' => 'dry_run',
                ],
            ];
        }

        $sourceNorm = in_array($source, [AdminNotificationCampaign::SOURCE_ADMIN_PANEL, AdminNotificationCampaign::SOURCE_FIREBASE], true)
            ? $source
            : AdminNotificationCampaign::SOURCE_FIREBASE;

        if ($scheduledFuture && $scheduledCarbon) {
            if ($this->firestore->isAvailable()) {
                try {
                    $this->firestore->setCampaign($campaignId, [
                        'title' => $title,
                        'body' => $body,
                        'segmentId' => $segmentId,
                        'segmentLabel' => $segmentLabel,
                        'countryFilter' => $countryFilter,
                        'cityFilter' => $cityFilter,
                        'targetedUsers' => $targetedUsers,
                        'reachableDevices' => $reachableDevices,
                        'estimatedReach' => $reachableDevices,
                        'sentCount' => 0,
                        'failedCount' => 0,
                        'skippedNoToken' => $skippedNoToken,
                        'status' => 'queued',
                        'errorMessage' => null,
                        'createdBy' => $createdBy,
                        'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                        'scheduledAt' => $scheduledCarbon->toIso8601String(),
                        'processingStartedAt' => null,
                        'completedAt' => null,
                        'metadata' => $metadata,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('admin_push.scheduled_campaign_firestore_write_failed', ['message' => $e->getMessage()]);
                }
            }

            $this->history->recordStart(
                $campaignId,
                $title,
                $body,
                $segmentId,
                $segmentLabel,
                $countryFilter,
                $cityFilter,
                $targetedUsers,
                $reachableDevices,
                $skippedNoToken,
                AdminNotificationCampaign::STATUS_QUEUED,
                $adminClaims,
                $actorUserId,
                $sourceNorm
            );

            $this->appendAdminActionSafe($adminClaims, $campaignId, $targetedUsers, $reachableDevices, 0, 0, $skippedNoToken, 'queued', 'success', 'Campaign queued for scheduled delivery.');

            return [
                'http_status' => 200,
                'payload' => [
                    'ok' => true,
                    'campaignId' => $campaignId,
                    'targetedUsers' => $targetedUsers,
                    'reachableDevices' => $reachableDevices,
                    'sent' => 0,
                    'failed' => 0,
                    'skippedNoToken' => $skippedNoToken,
                    'status' => 'queued',
                ],
            ];
        }

        if ($this->firestore->isAvailable()) {
            try {
                $this->firestore->setCampaign($campaignId, [
                    'title' => $title,
                    'body' => $body,
                    'segmentId' => $segmentId,
                    'segmentLabel' => $segmentLabel,
                    'countryFilter' => $countryFilter,
                    'cityFilter' => $cityFilter,
                    'targetedUsers' => $targetedUsers,
                    'reachableDevices' => $reachableDevices,
                    'estimatedReach' => $reachableDevices,
                    'sentCount' => 0,
                    'failedCount' => 0,
                    'skippedNoToken' => $skippedNoToken,
                    'status' => 'processing',
                    'errorMessage' => null,
                    'createdBy' => $createdBy,
                    'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'processingStartedAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'completedAt' => null,
                    'metadata' => $metadata,
                ]);
            } catch (\Throwable $e) {
                Log::warning('admin_push.campaign_start_firestore_write_failed', ['message' => $e->getMessage()]);
            }
        }

        $this->history->recordStart(
            $campaignId,
            $title,
            $body,
            $segmentId,
            $segmentLabel,
            $countryFilter,
            $cityFilter,
            $targetedUsers,
            $reachableDevices,
            $skippedNoToken,
            AdminNotificationCampaign::STATUS_PROCESSING,
            $adminClaims,
            $actorUserId,
            $sourceNorm
        );

        $concurrency = (int) config('admin_push.fcm_concurrency', 40);
        $chunkSize = (int) config('admin_push.user_query_chunk', 500);

        $sent = 0;
        $failed = 0;
        $clearIds = [];

        $dataPayload = array_merge(
            [
                'campaignId' => (string) $campaignId,
                'segmentId' => $segmentId,
            ],
            array_map('strval', $metadata)
        );

        $reachableQuery->orderBy('id')->chunkById($chunkSize, function ($users) use (
            &$sent,
            &$failed,
            &$clearIds,
            $title,
            $body,
            $dataPayload,
            $concurrency
        ) {
            $batch = [];
            foreach ($users as $user) {
                /** @var User $user */
                if (! is_string($user->fcm_token) || trim($user->fcm_token) === '') {
                    continue;
                }
                $batch[] = ['token' => $user->fcm_token, 'user_id' => (int) $user->id];
            }
            if ($batch === []) {
                return;
            }
            $r = $this->fcm->sendManyConcurrent($batch, $title, $body, $dataPayload, $concurrency);
            $sent += $r['sent'];
            $failed += $r['failed'];
            $clearIds = array_merge($clearIds, $r['clear_user_ids']);
        });

        $clearIds = array_values(array_unique($clearIds));
        if ($clearIds !== []) {
            User::query()->whereIn('id', $clearIds)->update(['fcm_token' => null]);
        }

        $deliveryFailed = $failed > 0 && $sent === 0;
        if ($deliveryFailed) {
            $finalStatus = AdminNotificationCampaign::STATUS_FAILED;
            $errorMessage = 'FCM send failed for all attempts in this run.';
        } elseif ($sent > 0 && $failed > 0) {
            $finalStatus = AdminNotificationCampaign::STATUS_PARTIALLY_SENT;
            $errorMessage = null;
        } else {
            $finalStatus = AdminNotificationCampaign::STATUS_SENT;
            $errorMessage = null;
        }

        if ($this->firestore->isAvailable()) {
            try {
                $this->firestore->setCampaign($campaignId, [
                    'sentCount' => $sent,
                    'failedCount' => $failed,
                    'skippedNoToken' => $skippedNoToken,
                    'status' => $finalStatus,
                    'errorMessage' => $errorMessage,
                    'completedAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                ]);
            } catch (\Throwable $e) {
                Log::warning('admin_push.campaign_complete_firestore_write_failed', ['message' => $e->getMessage()]);
            }
        }

        $this->history->recordComplete(
            $campaignId,
            $sent,
            $failed,
            $skippedNoToken,
            $finalStatus,
            $errorMessage
        );

        $actionResult = $deliveryFailed ? 'failed' : 'success';
        $actionDetails = $deliveryFailed
            ? 'FCM delivery failed for one or more batches.'
            : 'Campaign delivered from admin panel';

        $this->appendAdminActionSafe(
            $adminClaims,
            $campaignId,
            $targetedUsers,
            $reachableDevices,
            $sent,
            $failed,
            $skippedNoToken,
            $finalStatus,
            $actionResult,
            $actionDetails
        );

        if ($deliveryFailed) {
            return [
                'http_status' => 502,
                'payload' => [
                    'ok' => false,
                    'code' => 'delivery_failed',
                    'message' => 'FCM send failed for one or more batches.',
                    'campaignId' => $campaignId,
                    'targetedUsers' => $targetedUsers,
                    'reachableDevices' => $reachableDevices,
                    'sent' => $sent,
                    'failed' => $failed,
                    'skippedNoToken' => $skippedNoToken,
                    'status' => $finalStatus,
                ],
            ];
        }

        return [
            'http_status' => 200,
            'payload' => [
                'ok' => true,
                'campaignId' => $campaignId,
                'targetedUsers' => $targetedUsers,
                'reachableDevices' => $reachableDevices,
                'sent' => $sent,
                'failed' => $failed,
                'skippedNoToken' => $skippedNoToken,
                'status' => $finalStatus,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    protected function summaryFromCampaignDoc(string $campaignId, array $doc): array
    {
        return [
            'ok' => true,
            'campaignId' => $campaignId,
            'targetedUsers' => (int) ($doc['targetedUsers'] ?? $doc['targeted_users'] ?? 0),
            'reachableDevices' => (int) ($doc['reachableDevices'] ?? $doc['reachable_devices'] ?? $doc['estimatedReach'] ?? 0),
            'sent' => (int) ($doc['sentCount'] ?? $doc['sent'] ?? 0),
            'failed' => (int) ($doc['failedCount'] ?? $doc['failed'] ?? 0),
            'skippedNoToken' => (int) ($doc['skippedNoToken'] ?? 0),
            'status' => (string) ($doc['status'] ?? 'sent'),
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    protected function isStaleProcessing(array $doc): bool
    {
        $raw = $doc['processingStartedAt'] ?? null;
        if ($raw instanceof \DateTimeInterface) {
            $dt = Carbon::parse($raw);
        } elseif (is_object($raw) && method_exists($raw, 'get')) {
            try {
                $inner = $raw->get();
                $dt = $inner instanceof \DateTimeInterface ? Carbon::parse($inner) : Carbon::parse((string) $inner);
            } catch (\Throwable) {
                return true;
            }
        } elseif (is_string($raw)) {
            try {
                $dt = Carbon::parse($raw);
            } catch (\Throwable) {
                return true;
            }
        } elseif (is_array($raw) && isset($raw['_seconds'])) {
            $dt = Carbon::createFromTimestamp((int) $raw['_seconds']);
        } else {
            return true;
        }

        $minutes = (int) config('admin_push.processing_stale_minutes', 30);

        return $dt->lt(now()->subMinutes($minutes));
    }

    protected function normalizeCampaignId(mixed $clientId): string
    {
        if (is_string($clientId)) {
            $t = trim($clientId);
            if ($t !== '' && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $t)) {
                return $t;
            }
        }

        return str_replace('-', '', (string) Str::uuid());
    }

    protected function appendAdminActionSafe(
        stdClass $adminClaims,
        string $campaignId,
        int $targetedUsers,
        int $reachableDevices,
        int $sent,
        int $failed,
        int $skippedNoToken,
        string $campaignStatus,
        string $result,
        string $details
    ): void {
        if (! $this->firestore->isAvailable()) {
            return;
        }
        try {
            $this->firestore->appendAdminAction([
                'action' => 'push_notification_sent',
                'targetType' => 'broadcast',
                'targetId' => 'all',
                'targetTitle' => 'All users',
                'result' => $result,
                'details' => $details,
                'metadata' => [
                    'campaignId' => $campaignId,
                    'targetedUsers' => $targetedUsers,
                    'reachableDevices' => $reachableDevices,
                    'sent' => $sent,
                    'failed' => $failed,
                    'skippedNoToken' => $skippedNoToken,
                    'campaignStatus' => $campaignStatus,
                    'adminUid' => (string) ($adminClaims->sub ?? ''),
                    'adminEmail' => (string) ($adminClaims->email ?? ''),
                ],
                'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ]);
        } catch (\Throwable $e) {
            Log::error('admin_push.admin_action_append_failed', ['message' => $e->getMessage()]);
        }
    }
}
