<?php

namespace App\Services\Firebase;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use stdClass;

class FirebaseAdminAuthorizer
{
    public function __construct(
        protected FirestoreRestService $firestore,
        protected string $adminsCollection
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function assertIsAdmin(stdClass $claims): void
    {
        if ($this->hasAdminCustomClaims($claims)) {
            return;
        }

        $uid = (string) $claims->sub;
        if ($this->adminDocumentExists($uid)) {
            return;
        }

        $email = isset($claims->email) ? strtolower(trim((string) $claims->email)) : '';
        $configured = strtolower(trim((string) config('app.admin_email', '')));
        if ($email !== '' && $configured !== '' && $email === $configured) {
            return;
        }

        throw new InvalidArgumentException('Admin privileges required.');
    }

    protected function hasAdminCustomClaims(stdClass $claims): bool
    {
        if (isset($claims->admin) && $claims->admin === true) {
            return true;
        }
        if (isset($claims->role) && strtolower((string) $claims->role) === 'admin') {
            return true;
        }
        if (isset($claims->roles) && is_array($claims->roles) && in_array('admin', $claims->roles, true)) {
            return true;
        }

        return false;
    }

    protected function adminDocumentExists(string $uid): bool
    {
        if (! $this->firestore->isConfigured()) {
            return false;
        }

        try {
            return $this->firestore->documentExists($this->adminsCollection, $uid);
        } catch (\Throwable $e) {
            Log::warning('admin_push.firestore_admin_lookup_failed', ['message' => $e->getMessage()]);

            return false;
        }
    }
}
