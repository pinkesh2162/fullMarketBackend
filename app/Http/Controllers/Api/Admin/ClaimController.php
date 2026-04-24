<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminClaimResource;
use App\Models\Claim;
use App\Repositories\Admin\AdminClaimRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClaimController extends Controller
{
    public function __construct(
        protected AdminClaimRepository $claims
    ) {}

    public function summary(): JsonResponse
    {
        return $this->actionSuccess('admin_claims_summary_fetched', $this->claims->summary());
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:all,pending,approved,rejected',
            'q' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = $this->claims->paginateIndex($request);

        return $this->actionSuccess(
            'admin_claims_fetched',
            AdminClaimResource::collection($paginator->items()),
            self::HTTP_OK,
            $this->customizingResponseData($paginator)['pagination'] ?? null
        );
    }

    public function show(int $id): JsonResponse
    {
        $claim = $this->claims->findForAdmin($id);

        return $this->actionSuccess('admin_claim_fetched', new AdminClaimResource($claim));
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Claim::STATUS_PENDING, Claim::STATUS_APPROVED, Claim::STATUS_REJECTED])],
        ]);

        $claim = $this->claims->findForAdmin($id);
        $claim = $this->claims->changeStatus($claim, (string) $data['status']);
        $claim->load(['listing.store', 'listing.category', 'user', 'media']);

        return $this->actionSuccess('admin_claim_status_updated', new AdminClaimResource($claim));
    }

    public function destroy(int $id): JsonResponse
    {
        $claim = $this->claims->findForAdmin($id);
        $this->claims->softDelete($claim);

        return $this->actionSuccess('admin_claim_deleted');
    }
}
