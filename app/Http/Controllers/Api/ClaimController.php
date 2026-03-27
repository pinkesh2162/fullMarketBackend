<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimRequest;
use App\Repositories\ClaimRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ClaimController extends Controller
{
    /**
     * @var ClaimRepository
     */
    protected $claimRepo;

    /**
     * ClaimController constructor.
     *
     * @param ClaimRepository $claimRepository
     */
    public function __construct(ClaimRepository $claimRepository)
    {
        $this->claimRepo = $claimRepository;
    }

    /**
     * Store a newly created claim/remove ad request.
     *
     * @param ClaimRequest $request
     * @return JsonResponse
     */
    public function store(ClaimRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = Auth::id();

        $this->claimRepo->createClaim($data, $request->file('images'));

        return $this->actionSuccess('request_submitted');
    }
}
