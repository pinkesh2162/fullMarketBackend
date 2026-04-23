<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Repositories\Admin\AdminUserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        protected AdminUserRepository $adminUsers
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('os')) {
            $request->merge(['os' => strtolower((string) $request->input('os'))]);
        }

        $request->validate([
            'status' => 'nullable|in:all,active,suspend,blocked,deleted',
            'country' => 'nullable|string|max:120',
            'os' => 'nullable|in:all,android,ios,web',
            'q' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = $this->adminUsers->paginateIndex($request);

        return $this->actionSuccess(
            'admin_users_fetched',
            AdminUserResource::collection($paginator->items()),
            self::HTTP_OK,
            $this->customizingResponseData($paginator)['pagination'] ?? null
        );
    }

    public function countries(): JsonResponse
    {
        $list = $this->adminUsers->distinctCountryOptions();

        return $this->actionSuccess('admin_user_countries_fetched', [
            'countries' => $list,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->adminUsers->findForAdmin($id, true);

        return $this->actionSuccess('admin_user_fetched', new AdminUserResource($user));
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $user = $this->adminUsers->createFromRequest($request);

        return $this->actionSuccess('admin_user_created', new AdminUserResource($user), self::HTTP_CREATED);
    }

    public function update(UpdateAdminUserRequest $request, int $id): JsonResponse
    {
        $user = $this->adminUsers->findForAdmin($id, true);
        $user = $this->adminUsers->updateFromRequest($user, $request);

        return $this->actionSuccess('admin_user_updated', new AdminUserResource($user));
    }

    public function destroy(int $id): JsonResponse
    {
        $user = $this->adminUsers->findForAdmin($id, false);
        $this->adminUsers->softDelete($user);

        return $this->actionSuccess('admin_user_deleted');
    }
}
