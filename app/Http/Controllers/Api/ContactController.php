<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactMail;
use App\Repositories\ContactRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Models\Contact;

class ContactController extends Controller
{
    /**
     * @var ContactRepository
     */
    protected $contactRepository;

    /**
     * ContactController constructor.
     * @param  ContactRepository  $contactRepo
     */
    public function __construct(ContactRepository $contactRepo)
    {
        $this->contactRepository = $contactRepo;
    }

    /**
     * Send a contact email.
     *
     * @param  Request  $request
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
            Mail::to(config('app.admin_email'))->queue(new ContactMail($data));

            return $this->actionSuccess('request_submitted');
        } catch (\Exception $e) {
            return $this->serverError('contact_send_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getContacts()
    {
        $contacts = $this->contactRepository->getContacts(auth()->user());

        return $this->actionSuccess('Contacts retrieved successfully', $contacts);
    }

    /**
     * @param  Request  $request
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function discoverUsers(Request $request)
    {
        $type = $request->query('type');
        $typeClass = $type ? (\Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) ?? \App\Models\User::class) : null;

        $users = $this->contactRepository->discoverUsers($request->query('query'), $typeClass);

        return $this->actionSuccess('Entities discovered successfully', $users);
    }

    /**
     * @param  Request  $request
     * @param $id
     *
     *
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
            $typeClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type);

            $blockerId = $request->blocker_id ?? auth()->id();
            $blockerType = $request->blocker_type ?? 'user';
            $blockerClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($blockerType);

            if ($blockerId == $id && $blockerType == $type) {
                return $this->actionFailure('You cannot block yourself', null, 400);
            }

            $blocker = $blockerClass::find($blockerId);
            if (!$blocker) {
                return $this->actionFailure('Blocker entity not found', null, 404);
            }

            // Verify ownership if blocker is store
            if ($blockerType == 'store' && $blocker->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }

            $blocker->blockedEntities()->updateOrCreate([
                'blocked_id' => $id,
                'blocked_type' => $typeClass,
            ]);

            return $this->actionSuccess('Entity blocked successfully');
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * @param $id
     *
     *
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
            $typeClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type);

            $blockerId = $request->blocker_id ?? auth()->id();
            $blockerType = $request->blocker_type ?? 'user';
            $blockerClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($blockerType);

            $blocker = $blockerClass::find($blockerId);
            if (!$blocker) {
                return $this->actionFailure('Blocker entity not found', null, 404);
            }

            // Verify ownership if blocker is store
            if ($blockerType == 'store' && $blocker->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }

            $blocker->blockedEntities()
                ->where('blocked_id', $id)
                ->where('blocked_type', $typeClass)
                ->delete();

            return $this->actionSuccess('Entity unblocked successfully');
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     *
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBlockedUsers(Request $request)
    {
        $blockerId = $request->query('blocker_id', auth()->id());
        $blockerType = $request->query('blocker_type', 'user');
        $blockerClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($blockerType);

        $blocker = $blockerClass::find($blockerId);
        if (!$blocker) {
            return $this->actionFailure('Blocker entity not found', null, 404);
        }

        // Verify ownership if blocker is store
        if ($blockerType == 'store' && $blocker->user_id != auth()->id()) {
            return $this->actionFailure('Unauthorized store access', null, 403);
        }

        $type = $request->query('type');
        $query = $blocker->blockedEntities()->with('blocked');

        if ($type) {
            $typeClass = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type);
            $query->where('blocked_type', $typeClass);
        }

        $blockedEntities = $query->get();

        return $this->actionSuccess('Blocked entities retrieved successfully', $blockedEntities);
    }
}
