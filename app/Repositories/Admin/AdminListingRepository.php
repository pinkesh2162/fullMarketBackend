<?php

namespace App\Repositories\Admin;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminListingRepository
{
    /**
     * @return array{total: int, active: int, expired: int, reported: int, featured: int, sold_or_inactive: int}
     */
    public function summary(): array
    {
        $base = Listing::query()->whereNull('deleted_at');

        $total = (clone $base)->count();
        $active = (clone $base)->where(function ($a) {
            $a->where('availability', true)->orWhereNull('availability');
        })->count();
        $expired = (clone $base)->where('availability', Listing::SOLD)->count();
        $featured = (clone $base)->where('is_featured', true)->count();
        $reported = (int) DB::table('listing_reports')
            ->selectRaw('COUNT(DISTINCT listing_id) as c')
            ->value('c');

        return [
            'total' => $total,
            'active' => $active,
            'expired' => $expired,
            'reported' => $reported,
            'featured' => $featured,
            'sold_or_inactive' => $expired,
        ];
    }

    public function paginateIndex(Request $request): LengthAwarePaginator
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));
        $q = Listing::query()
            ->withTrashed()
            ->with(['store', 'category', 'user', 'media'])
            ->withCount('reports as reports_count')
            ->orderByDesc('id');

        $this->applyStatus($q, (string) $request->query('status', 'all'));
        $this->applyCategory($q, (string) $request->query('category_id', $request->query('category', '')));
        $this->applySearch($q, (string) $request->query('q', $request->query('search', '')));

        return $q->paginate($perPage);
    }

    public function findForAdmin(int $id): Listing
    {
        return Listing::query()
            ->withTrashed()
            ->with(['store', 'category', 'user', 'media'])
            ->withCount('reports as reports_count')
            ->whereKey($id)
            ->firstOrFail();
    }

    public function setFeatured(Listing $listing, bool $on): Listing
    {
        $listing->is_featured = $on;
        $listing->featured_at = $on ? now() : null;
        $listing->save();

        return $listing->fresh();
    }

    public function softDelete(Listing $listing): void
    {
        if ($listing->trashed()) {
            return;
        }
        $listing->delete();
    }

    public function restore(Listing $listing): Listing
    {
        if (! $listing->trashed()) {
            return $listing->fresh(['store', 'category', 'user', 'media']);
        }

        $listing->restore();
        $listing->featured_at = $listing->is_featured ? ($listing->featured_at ?? now()) : null;
        $listing->save();

        return $listing->fresh(['store', 'category', 'user', 'media']);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Listing>  $q
     */
    private function applyStatus($q, string $status): void
    {
        $status = strtolower($status) ?: 'all';
        if ($status === 'all' || $status === '') {
            return;
        }
        if ($status === 'active') {
            $q->whereNull('listings.deleted_at')
                ->where(function ($a) {
                    $a->where('listings.availability', true)
                        ->orWhereNull('listings.availability');
                });

            return;
        }
        if ($status === 'expired' || $status === 'inactive') {
            $q->whereNull('listings.deleted_at')->where('listings.availability', Listing::SOLD);

            return;
        }
        if ($status === 'deleted') {
            $q->whereNotNull('listings.deleted_at');

            return;
        }
        if ($status === 'reported') {
            $q->whereNull('listings.deleted_at')
                ->whereIn('listings.id', function ($s) {
                    $s->select('listing_id')
                        ->from('listing_reports')
                        ->groupBy('listing_id');
                });

            return;
        }
        if ($status === 'featured') {
            $q->whereNull('listings.deleted_at')
                ->where('listings.is_featured', true);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Listing>  $q
     */
    private function applyCategory($q, string $categoryId): void
    {
        $categoryId = trim($categoryId);
        if ($categoryId === '' || $categoryId === 'all') {
            return;
        }
        if (ctype_digit($categoryId)) {
            $q->where('listings.service_category', (int) $categoryId);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Listing>  $q
     */
    private function applySearch($q, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $q->where(function ($w) use ($term, $like) {
            $w->where('listings.title', 'like', $like)
                ->orWhere('listings.firebase_id', 'like', $like)
                ->orWhere('listings.search_keyword', 'like', $like)
                ->orWhereHas('store', function ($s) use ($like) {
                    $s->where('name', 'like', $like);
                });
            if (ctype_digit($term)) {
                $w->orWhere('listings.id', (int) $term);
            }
        });
    }
}
