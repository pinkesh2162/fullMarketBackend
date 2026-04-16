<?php

namespace App\Services\AdminPush;

use App\Services\Firebase\FirestoreRestService;
use Illuminate\Support\Facades\Log;

class AdminPushFirestoreWriter
{
    public function __construct(
        protected FirestoreRestService $firestore,
        protected string $campaignsCollection,
        protected string $adminActionsCollection
    ) {}

    public function isAvailable(): bool
    {
        return $this->firestore->isConfigured();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCampaignData(string $campaignId): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            return $this->firestore->getDocumentFields($this->campaignsCollection, $campaignId);
        } catch (\Throwable $e) {
            Log::warning('admin_push.firestore_read_campaign_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Upserts campaign fields (Firestore REST PATCH).
     *
     * @param  array<string, mixed>  $fields
     */
    public function setCampaign(string $campaignId, array $fields): void
    {
        $this->firestore->patchDocument($this->campaignsCollection, $campaignId, $fields);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function appendAdminAction(array $payload): void
    {
        $this->firestore->addDocument($this->adminActionsCollection, $payload);
    }
}
