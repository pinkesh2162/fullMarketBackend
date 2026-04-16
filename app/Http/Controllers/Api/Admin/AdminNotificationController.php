<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendAdminPushRequest;
use App\Services\AdminPush\AdminPushBroadcastService;
use Illuminate\Http\JsonResponse;

class AdminNotificationController extends Controller
{
    public function __construct(
        protected AdminPushBroadcastService $broadcast
    ) {}

    public function send(SendAdminPushRequest $request): JsonResponse
    {
        /** @var \stdClass $claims */
        $claims = $request->attributes->get('firebase_claims');

        $input = $request->validated();
        $input['dryRun'] = $request->boolean('dryRun');
        if (! array_key_exists('metadata', $input)) {
            $input['metadata'] = [];
        }

        $result = $this->broadcast->handle($input, $claims);

        return response()->json($result['payload'], $result['http_status']);
    }
}
