<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactMail;
use App\Models\Contact;
use App\Repositories\ContactRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    /**
     * @var ContactRepository
     */
    protected $contactRepository;

    /**
     * ContactController constructor.
     */
    public function __construct(ContactRepository $contactRepo)
    {
        $this->contactRepository = $contactRepo;
    }

    /**
     * Send a contact email.
     *
     * @return JsonResponse
     */
    public function send(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        try {
            // Store contact message in the database
            Contact::create($data);

            // Send email to the support address
            Mail::to(config('app.admin_email'))->send(new ContactMail($data));

            return $this->actionSuccess('request_submitted');
        } catch (\Exception $e) {
            return $this->serverError('contact_send_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     */
    public function getContacts()
    {
        $contacts = $this->contactRepository->getContacts(auth()->user());

        return $this->actionSuccess('Contacts retrieved successfully', $contacts);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     */
    public function discoverUsers(Request $request)
    {
        $request->validate([
            'query' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $perPageRaw = $request->query('per_page');
        $perPage = ($perPageRaw === null || $perPageRaw === '') ? null : (int) $perPageRaw;
        $page = (int) $request->query('page', 1);

        $result = $this->contactRepository->discoverUsers(
            $request->query('query'),
            $perPage,
            $page
        );

        if ($result instanceof LengthAwarePaginator) {
            return $this->actionSuccess(
                'Users discovered successfully',
                $result->items(),
                self::HTTP_OK,
                [
                    'current_page' => $result->currentPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                    'last_page' => $result->lastPage(),
                ]
            );
        }

        return $this->actionSuccess(
            'Users discovered successfully',
            $result->values()->all(),
            self::HTTP_OK,
            [
                'current_page' => 1,
                'per_page' => null,
                'total' => $result->count(),
                'last_page' => 1,
            ]
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function blockUser(Request $request, $id)
    {
        $request->validate([
            'type' => 'nullable|in:user,store',
            'blocker_id' => 'nullable|numeric',
            'blocker_type' => 'nullable|in:user,store',
        ]);

        try {
            $type = $request->type ?? 'user';

            $blockerId = $request->blocker_id ?? auth()->id();
            $blockerType = $request->blocker_type ?? 'user';
            $blockerClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($blockerType);

            if ($blockerId == $id && $blockerType == $type) {
                return $this->actionFailure('You cannot block yourself', null, 400);
            }

            $blocker = $blockerClass::find($blockerId);
            if (! $blocker) {
                return $this->actionFailure('Blocker entity not found', null, 404);
            }

            // Verify ownership if blocker is store
            if ($blockerType == 'store' && $blocker->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }

            /**
             * Store morph **aliases** (`user` / `store`), matching {@see Model::getMorphClass()} and
             * {@see CanInteractSocially::hasBlocked()} — not the concrete class name.
             */
            $blocker->blockedEntities()->updateOrCreate([
                'blocked_id' => $id,
                'blocked_type' => $type,
            ]);

            return $this->actionSuccess('Entity blocked successfully');
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function unblockUser(Request $request, $id)
    {
        $request->validate([
            'type' => 'nullable|in:user,store',
            'blocker_id' => 'nullable|numeric',
            'blocker_type' => 'nullable|in:user,store',
        ]);

        try {
            $type = $request->type ?? 'user';

            $blockerId = $request->blocker_id ?? auth()->id();
            $blockerType = $request->blocker_type ?? 'user';
            $blockerClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($blockerType);

            $blocker = $blockerClass::find($blockerId);
            if (! $blocker) {
                return $this->actionFailure('Blocker entity not found', null, 404);
            }

            // Verify ownership if blocker is store
            if ($blockerType == 'store' && $blocker->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }

            $blocker->blockedEntities()
                ->where('blocked_id', $id)
                ->where('blocked_type', $type)
                ->delete();

            return $this->actionSuccess('Entity unblocked successfully');
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBlockedUsers(Request $request)
    {
        $blockerId = $request->query('blocker_id', auth()->id());
        $blockerType = $request->query('blocker_type', 'user');
        $blockerClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($blockerType);

        $blocker = $blockerClass::find($blockerId);
        if (! $blocker) {
            return $this->actionFailure('Blocker entity not found', null, 404);
        }

        // Verify ownership if blocker is store
        if ($blockerType == 'store' && $blocker->user_id != auth()->id()) {
            return $this->actionFailure('Unauthorized store access', null, 403);
        }

        $type = $request->query('type');
        $query = $blocker->blockedEntities()->with('blocked');

        if ($type) {
            $query->where('blocked_type', $type);
        }

        $blockedEntities = $query->get();

        return $this->actionSuccess('Blocked entities retrieved successfully', $blockedEntities);
    }
}
