<?php

namespace App\Services\FirebaseMigration;

/**
 * Persists Firestore id → local id maps for idempotent re-runs.
 *
 * @phpstan-type TState array{
 *   users?: array<string, int>,
 *   categories?: array<string, int>,
 *   listings?: array<string, int>,
 *   stores_by_owner_uid?: array<string, int>,
 *   stores_by_doc_id?: array<string, int>,
 *   category_dedup?: array<string, int>
 * }
 */
class MigrationState
{
    /** @var TState */
    protected array $data = [
        'users' => [],
        'categories' => [],
        'listings' => [],
        'stores_by_owner_uid' => [],
        'stores_by_doc_id' => [],
        'category_dedup' => [],
    ];

    public function __construct(
        protected string $path
    ) {
        $this->load();
    }

    public function load(): void
    {
        if (! is_file($this->path)) {
            return;
        }

        $raw = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($raw)) {
            return;
        }

        foreach (array_keys($this->data) as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                $this->data[$key] = array_map('intval', $raw[$key]);
            }
        }
    }

    public function save(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->path,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    public function getUserId(string $firebaseUid): ?int
    {
        return $this->data['users'][$firebaseUid] ?? null;
    }

    public function setUserId(string $firebaseUid, int $id): void
    {
        $this->data['users'][$firebaseUid] = $id;
    }

    public function getCategoryId(string $firebaseDocId): ?int
    {
        return $this->data['categories'][$firebaseDocId] ?? null;
    }

    public function setCategoryId(string $firebaseDocId, int $id): void
    {
        $this->data['categories'][$firebaseDocId] = $id;
    }

    public function forgetCategory(string $firebaseDocId): void
    {
        unset($this->data['categories'][$firebaseDocId]);
    }

    public function getListingId(string $firebaseDocId): ?int
    {
        return $this->data['listings'][$firebaseDocId] ?? null;
    }

    public function setListingId(string $firebaseDocId, int $id): void
    {
        $this->data['listings'][$firebaseDocId] = $id;
    }

    public function forgetListing(string $firebaseDocId): void
    {
        unset($this->data['listings'][$firebaseDocId]);
    }

    public function getStoreIdByOwnerUid(string $firebaseUid): ?int
    {
        return $this->data['stores_by_owner_uid'][$firebaseUid] ?? null;
    }

    public function setStoreIdByOwnerUid(string $firebaseUid, int $id): void
    {
        $this->data['stores_by_owner_uid'][$firebaseUid] = $id;
    }

    public function getStoreIdByDocId(string $firebaseStoreDocId): ?int
    {
        return $this->data['stores_by_doc_id'][$firebaseStoreDocId] ?? null;
    }

    public function setStoreIdByDocId(string $firebaseStoreDocId, int $id): void
    {
        $this->data['stores_by_doc_id'][$firebaseStoreDocId] = $id;
    }

    public function getCategoryDedupLocalId(string $dedupKey): ?int
    {
        return $this->data['category_dedup'][$dedupKey] ?? null;
    }

    public function setCategoryDedup(string $dedupKey, int $localId): void
    {
        $this->data['category_dedup'][$dedupKey] = $localId;
    }

    public function reset(): void
    {
        foreach (array_keys($this->data) as $k) {
            $this->data[$k] = [];
        }
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
