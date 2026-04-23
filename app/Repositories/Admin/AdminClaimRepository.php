<?php

namespace App\Repositories\Admin;

use App\Models\Claim;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class AdminClaimRepository
{
    /**
     * @return array{total_requests:int,pending:int,approved:int,rejected:int}
     */
    public function summary(): array
    {
        $base = Claim::query()->whereNull('deleted_at');

        return [
            'total_requests' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', Claim::STATUS_PENDING)->count(),
            'approved' => (clone $base)->where('status', Claim::STATUS_APPROVED)->count(),
            'rejected' => (clone $base)->where('status', Claim::STATUS_REJECTED)->count(),
        ];
    }

    public function paginateIndex(Request $request): LengthAwarePaginator
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $q = Claim::query()
            ->with(['listing.store', 'listing.category', 'user', 'media'])
            ->whereNull('claims.deleted_at')
            ->orderByDesc('claims.id');

        $this->applyStatus($q, (string) $request->query('status', 'all'));
        $this->applySearch($q, (string) $request->query('q', $request->query('search', '')));

        return $q->paginate($perPage);
    }

    public function findForAdmin(int $id): Claim
    {
        return Claim::query()
            ->with(['listing.store', 'listing.category', 'user', 'media'])
            ->whereNull('claims.deleted_at')
            ->whereKey($id)
            ->firstOrFail();
    }

    public function changeStatus(Claim $claim, string $status)
    {
        $claim->update(['status' => $status]);

        return $claim->fresh();
    }

    public function softDelete(Claim $claim): void
    {
        if (! $claim->trashed()) {
            $claim->delete();
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Claim>  $q
     */
    private function applyStatus($q, string $status): void
    {
        $status = strtolower(trim($status));
        if ($status === '' || $status === 'all') {
            return;
        }
        if (in_array($status, [Claim::STATUS_PENDING, Claim::STATUS_APPROVED, Claim::STATUS_REJECTED], true)) {
            $q->where('claims.status', $status);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Claim>  $q
     */
    private function applySearch($q, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $q->where(function ($w) use ($term, $like) {
            $w->where('claims.full_name', 'like', $like)
                ->orWhere('claims.email', 'like', $like)
                ->orWhere('claims.phone', 'like', $like)
                ->orWhere('claims.description', 'like', $like)
                ->orWhereHas('listing', function ($lq) use ($like) {
                    $lq->where('title', 'like', $like)
                        ->orWhere('firebase_id', 'like', $like)
                        ->orWhere('search_keyword', 'like', $like)
                        ->orWhereHas('store', function ($sq) use ($like) {
                            $sq->where('name', 'like', $like);
                        });
                });

            if (ctype_digit($term)) {
                $id = (int) $term;
                $w->orWhere('claims.id', $id)
                    ->orWhere('claims.listing_id', $id)
                    ->orWhere('claims.user_id', $id);
            }
        });
    }
}
