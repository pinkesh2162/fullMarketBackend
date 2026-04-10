<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Models\User;
use App\Models\FriendRequest;
use Exception;

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
     * @param $entity
     *
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function getContacts($entity)
    {
        try {
            // $friends = $entity->friends();
            // if ($friends->count() > 0) {
            //     dd($friends->get());
            //     return $entity->friends()->get();
            // }
            return $entity->friends();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }

    /**
     * @param  null  $query
     * @param string $type
     *
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function discoverUsers($query = null, $type = null)
    {
        try {
            $user = auth()->user();

            if (!$type) {
                $morphMap = \Illuminate\Database\Eloquent\Relations\Relation::morphMap();
                if (empty($morphMap)) {
                    $morphMap = ['user' => User::class, 'store' => \App\Models\Store::class];
                }

                $results = collect();
                foreach ($morphMap as $class) {
                    $results = $results->concat($this->getDiscoveryQuery($query, $class, $user)->get());
                }
                return $results;
            }

            return $this->getDiscoveryQuery($query, $type, $user)->get();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), $ex->getCode());
        }
    }

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
                if ($req->sender_type == $typeAlias) $ids[] = $req->sender_id;
                if ($req->receiver_type == $typeAlias) $ids[] = $req->receiver_id;
                return $ids;
            })
            ->concat($blockedByMe)
            ->concat($blockedMe);

        if ($typeAlias == $userAlias) {
            $excludedIds->push($user->id);
        }

        $excludedIds = $excludedIds->unique()->values();

        return $type::whereNotIn('id', $excludedIds)
            ->when($query, function ($q) use ($query, $type) {
                $q->where(function ($sub) use ($query, $type) {
                    if ($type == User::class) {
                        $sub->where('first_name', 'like', "%{$query}%")
                            ->orWhere('last_name', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%");
                    } else {
                        $sub->where('name', 'like', "%{$query}%");
                    }
                });
            });
    }
}
