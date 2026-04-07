<?php

namespace App\Services\FirebaseImport;

class ImportState
{
    /** @var array<string, array<string, int>> */
    protected array $data = [
        'users' => [],
        'categories' => [],
        'listings' => [],
        'stores' => [],
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

        foreach (['users', 'categories', 'listings', 'stores'] as $key) {
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

    public function getStoreId(string $firebaseUserUid): ?int
    {
        return $this->data['stores'][$firebaseUserUid] ?? null;
    }

    public function setStoreId(string $firebaseUserUid, int $id): void
    {
        $this->data['stores'][$firebaseUserUid] = $id;
    }

    public function forgetUser(string $firebaseUid): void
    {
        unset($this->data['users'][$firebaseUid]);
    }

    public function forgetCategory(string $firebaseDocId): void
    {
        unset($this->data['categories'][$firebaseDocId]);
    }

    public function forgetStore(string $firebaseUserUid): void
    {
        unset($this->data['stores'][$firebaseUserUid]);
    }

    public function reset(): void
    {
        $this->data = [
            'users' => [],
            'categories' => [],
            'listings' => [],
            'stores' => [],
        ];
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
