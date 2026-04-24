<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendAdminPanelNotificationRequest;
use App\Http\Requests\Admin\SendAdminPushRequest;
use App\Models\AdminNotificationCampaign;
use App\Models\User;
use App\Services\AdminPush\AdminPushBroadcastService;
use App\Services\AdminPush\UserSegmentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminNotificationController extends Controller
{
    protected $segments;

    protected $broadcast;

    public function __construct(
        UserSegmentQuery $segments,
        AdminPushBroadcastService $broadcast
    ) {
        $this->broadcast = $broadcast;
        $this->segments = $segments;
    }

    public function send(SendAdminPushRequest $request): JsonResponse
    {
        /** @var \stdClass $claims */
        $claims = $request->attributes->get('firebase_claims');

        $input = $request->validated();
        $input['dryRun'] = $request->boolean('dryRun');
        if (! array_key_exists('metadata', $input)) {
            $input['metadata'] = [];
        }

        $result = $this->broadcast->handle($input, $claims, null, AdminNotificationCampaign::SOURCE_FIREBASE);

        return response()->json($result['payload'], $result['http_status']);
    }

    public function audience(Request $request): JsonResponse
    {
        $request->validate([
            'segmentId' => 'nullable|string|in:all,android,ios,newUsers,inactive,withStore,withoutStore,featuredListings',
            'country' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
        ]);

        $country = (string) $request->query('country', 'all');
        $city = (string) $request->query('city', 'all');
        $selectedSegment = (string) $request->query('segmentId', 'all');

        $segments = [
            'all', 'android', 'ios', 'newUsers', 'inactive', 'withStore', 'withoutStore', 'featuredListings',
        ];
        $cards = [];
        foreach ($segments as $segmentId) {
            $cards[$segmentId] = (int) $this->segments->baseQuery([
                'segmentId' => $segmentId,
                'countryFilter' => $country,
                'cityFilter' => $city,
            ])->count();
        }

        $selectedBase = $this->segments->baseQuery([
            'segmentId' => $selectedSegment,
            'countryFilter' => $country,
            'cityFilter' => $city,
        ]);
        $targetedUsers = (int) (clone $selectedBase)->count();
        $reachableDevices = (int) (clone $selectedBase)->whereNotNull('fcm_token')->where('fcm_token', '!=',
            '')->count();

        return $this->actionSuccess('admin_notification_audience_fetched', [
            'segment_cards' => $cards,
            'selected_segment' => $selectedSegment,
            'selected_segment_label' => UserSegmentQuery::segmentLabel($selectedSegment),
            'country' => $country,
            'city' => $city,
            'estimated_audience' => $targetedUsers,
            'reachable_devices' => $reachableDevices,
            'segments' => array_map(function ($segmentId) {
                return [
                    'id' => $segmentId,
                    'label' => UserSegmentQuery::segmentLabel($segmentId),
                ];
            }, $segments),
        ]);
    }

    public function filters(Request $request): JsonResponse
    {
        $request->validate([
            'country' => 'nullable|string|max:120',
        ]);
        $selectedCountry = (string) $request->query('country', 'all');

        $countries = $this->countryOptions();
        $cities = $this->cityOptions($selectedCountry);

        return $this->actionSuccess('admin_notification_filters_fetched', [
            'countries' => $countries,
            'cities' => $cities,
            'selected_country' => $selectedCountry,
        ]);
    }

    public function sendNotification(SendAdminPanelNotificationRequest $request): JsonResponse
    {
        $payload = $request->toBroadcastPayload();
        $user = $request->user();
        $claims = (object) [
            'sub' => (string) ($user?->id ?? 'admin'),
            'email' => (string) ($user?->email ?? 'admin@local'),
        ];

        $result = $this->broadcast->handle($payload, $claims, $user?->id, AdminNotificationCampaign::SOURCE_ADMIN_PANEL);

        return response()->json($result['payload'], $result['http_status']);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:all,sent,failed,partially_sent,processing,queued',
            'search' => 'nullable|string|max:200',
            'sort' => 'nullable|string|in:created_at,sent_at',
            'order' => 'nullable|string|in:asc,desc',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $status = isset($validated['status']) ? (string) $validated['status'] : 'all';
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $sort = (string) ($validated['sort'] ?? 'created_at');
        $order = strtolower((string) ($validated['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = AdminNotificationCampaign::query()->where('dry_run', false);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('body', 'like', $term)
                    ->orWhere('public_id', 'like', $term);
            });
        }

        $query->orderBy($sort, $order);

        $paginator = $query->paginate($perPage)->appends($request->query());
        $items = $paginator->getCollection()->map(fn (AdminNotificationCampaign $c) => $c->toApiArray());
        $paginator->setCollection($items);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];

        return $this->actionSuccess('admin_notification_history_fetched', $paginator->items(), self::HTTP_OK, $meta);
    }

    public function historyShow(int $id): JsonResponse
    {
        $campaign = AdminNotificationCampaign::query()->where('dry_run', false)->find($id);
        if ($campaign === null) {
            return $this->notFound('Admin notification campaign not found.');
        }

        return $this->actionSuccess('admin_notification_history_item_fetched', $campaign->toApiArray());
    }

    /**
     * @return list<string>
     */
    private function countryOptions(): array
    {
        $vals = [];
        if (DB::getDriverName() === 'mysql') {
            $rows = DB::table('users')
                ->whereNull('deleted_at')
                ->whereNotNull('location')
                ->selectRaw("COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.country')), ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.countryName')), ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.country_name')), '')
                ) as c")
                ->get();
            foreach ($rows as $row) {
                if (is_string($row->c) && trim($row->c) !== '') {
                    $vals[trim($row->c)] = true;
                }
            }
        } else {
            User::query()->whereNull('deleted_at')->orderBy('id')->chunk(500, function ($chunk) use (&$vals) {
                foreach ($chunk as $u) {
                    $loc = is_array($u->location) ? $u->location : [];
                    $c = $loc['country'] ?? $loc['countryName'] ?? $loc['country_name'] ?? null;
                    if (is_string($c) && trim($c) !== '') {
                        $vals[trim($c)] = true;
                    }
                }
            });
        }
        ksort($vals, SORT_NATURAL);

        return array_values(array_keys($vals));
    }

    /**
     * @return list<string>
     */
    private function cityOptions(string $country): array
    {
        $vals = [];
        $country = trim($country);
        User::query()->whereNull('deleted_at')->orderBy('id')->chunk(500, function ($chunk) use (&$vals, $country) {
            foreach ($chunk as $u) {
                $loc = is_array($u->location) ? $u->location : [];
                $city = $loc['city'] ?? null;
                if (! is_string($city) || trim($city) === '') {
                    continue;
                }
                if ($country !== '' && strtolower($country) !== 'all') {
                    $uc = (string) ($loc['country'] ?? $loc['countryName'] ?? $loc['country_name'] ?? '');
                    if (mb_strtolower(trim($uc)) !== mb_strtolower($country)) {
                        continue;
                    }
                }
                $vals[trim($city)] = true;
            }
        });
        ksort($vals, SORT_NATURAL);

        return array_values(array_keys($vals));
    }
}
