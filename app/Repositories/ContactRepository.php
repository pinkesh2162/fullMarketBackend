<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Models\FriendRequest;
use App\Models\Store;
use App\Models\User;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ContactRepository extends BaseRepository
{
    /**
     * @return array
     */
    public function getFieldsSearchable()
    {
        return [];
    }

    /**
     * @return string
     */
    public function model()
    {
        return User::class;
    }

    /**
     * @return mixed
     *
     * @throws ApiOperationFailedException
     */
    public function getContacts($entity)
    {
        try {
            if (! $entity instanceof User) {
                return $entity->friends();
            }

            /** @var User $user */
            $user = $entity;
            $merged = collect($user->friends());

            $storeIds = $user->stores()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $primary = $user->store;
            if ($primary && ! in_array((int) $primary->id, $storeIds, true)) {
                $storeIds[] = (int) $primary->id;
            }

            foreach (array_values(array_unique($storeIds)) as $sid) {
                $store = Store::find($sid);
                if ($store) {
                    $merged = $merged->merge($store->friends());
                }
            }

            /** Never list the viewer's own store(s) as if they were an external contact. */
            $merged = $merged->filter(function ($friend) use ($user) {
                if ($friend instanceof Store) {
                    return (int) $friend->user_id !== (int) $user->id;
                }

                return true;
            });

            /**
             * Accepted friendships only (see friends()). After user↔store accept, the API also creates
             * a user↔user bridge row so the owner appears here without duplicating logic.
             */
            return $merged
                ->unique(fn ($friend) => $friend->getMorphClass().'-'.$friend->id)
                ->values();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * Number of accepted contacts (same rules as getContacts).
     *
     * @throws ApiOperationFailedException
     */
    public function countContacts($entity): int
    {
        try {
            return $this->getContacts($entity)->count();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * Discover people: **User rows only** (no stores). Optional search on name/email.
     * If `per_page` is null, returns all matching users (no slice). Otherwise paginates.
     *
     * @return \Illuminate\Support\Collection<int, User>|LengthAwarePaginator
     *
     * @throws ApiOperationFailedException
     */
    public function discoverUsers(?string $search = null, ?int $perPage = null, int $page = 1)
    {
        try {
            /** @var User $user */
            $user = auth()->user();

            $builder = $this->getDiscoveryQuery($search, User::class, $user)
                ->orderBy('first_name')
                ->orderBy('last_name');

            if ($perPage === null || $perPage < 1) {
                return $builder->get();
            }

            return $builder->paginate($perPage, ['*'], 'page', max(1, $page));
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @param  class-string  $type
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function getDiscoveryQuery($query, $type, $user)
    {
        $morphMap = \Illuminate\Database\Eloquent\Relations\Relation::morphMap();
        $typeAlias = array_search($type, $morphMap) ?: $type;
        $userAlias = $user->getMorphClass();

        $blockedByMe = $user->blockedEntities()
            ->where('blocked_type', $typeAlias)
            ->pluck('blocked_id')
            ->toArray();
        $blockedMe = $user->blockedByEntities()
            ->where('blocker_type', $typeAlias)
            ->pluck('blocker_id')
            ->toArray();

        $excludedIds = FriendRequest::where(function ($q) use ($user, $userAlias, $typeAlias) {
            $q->where('sender_id', $user->id)
                ->where('sender_type', $userAlias)
                ->where('receiver_type', $typeAlias);
        })->orWhere(function ($q) use ($user, $userAlias, $typeAlias) {
            $q->where('receiver_id', $user->id)
                ->where('receiver_type', $userAlias)
                ->where('sender_type', $typeAlias);
        })->get(['sender_id', 'sender_type', 'receiver_id', 'receiver_type'])
            ->flatMap(function ($req) use ($typeAlias) {
                $ids = [];
                if ($req->sender_type == $typeAlias) {
                    $ids[] = $req->sender_id;
                }
                if ($req->receiver_type == $typeAlias) {
                    $ids[] = $req->receiver_id;
                }

                return $ids;
            })
            ->concat($blockedByMe)
            ->concat($blockedMe);

        if ($typeAlias == $userAlias) {
            $excludedIds->push($user->id);
        }

        $excludedIds = $excludedIds->unique()->values();

        /**
         * If you sent (or have) a friend request to someone's **store**, don't also show that
         * person under People in Discover — they're represented by the store row / Sent tab until accepted.
         */
        if ($type === User::class) {
            $storeReceiverIds = FriendRequest::query()
                ->where('sender_id', $user->id)
                ->where('sender_type', $userAlias)
                ->where('receiver_type', 'store')
                ->whereIn('status', ['pending', 'accepted'])
                ->pluck('receiver_id');
            if ($storeReceiverIds->isNotEmpty()) {
                $ownerIds = Store::query()
                    ->whereIn('id', $storeReceiverIds)
                    ->pluck('user_id');
                $excludedIds = $excludedIds->merge($ownerIds)->unique()->values();
            }
        }

        $builder = $type::query()->whereNotIn('id', $excludedIds);

        $builder->when($query !== null && trim((string) $query) !== '', function ($q) use ($query, $type) {
            $term = trim((string) $query);
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(function ($sub) use ($like, $type) {
                if ($type == User::class) {
                    $sub->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereRaw(
                            "CONCAT(COALESCE(TRIM(first_name),''), ' ', COALESCE(TRIM(last_name),'')) LIKE ?",
                            [$like]
                        );
                } else {
                    $sub->where('name', 'like', $like);
                }
            });
        });

        return $builder;
    }
}
