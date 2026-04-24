<?php

namespace App\Repositories\Admin;

use App\Models\Listing;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminDashboardRepository
{
    /**
     * @return array<string, mixed>
     */
    public function getDashboard(string $period): array
    {
        $now = now();
        [$currentStart, $currentEnd, $prevStart, $prevEnd, $chartStart, $chartEnd, $chartDayCount] = $this->resolveTimeWindows($period, $now);
        $allWindowDays = max(1, (int) config('admin_dashboard.all_period_chart_days', 90));

        $isMysql = DB::getDriverName() === 'mysql';
        $lastActivitySql = $this->lastUserActivityExpression($isMysql);

        $userBase = User::query()->withTrashed();
        $totalUsers = (clone $userBase)->count();

        $isAll = $period === 'all' || $period === '';
        if ($isAll) {
            $allStart = $now->copy()->subDays($allWindowDays - 1)->startOfDay();
            $allPrevEnd = $allStart->copy()->subSecond();
            $allPrevStart = $allStart->copy()->subDays($allWindowDays)->startOfDay();
            $newUsersCurrent = (clone $userBase)->whereNull('deleted_at')->whereBetween('created_at', [$allStart, $now])->count();
            $newUsersPrevious = (clone $userBase)->whereNull('deleted_at')->whereBetween('created_at', [$allPrevStart, $allPrevEnd])->count();
        } else {
            $newUsersCurrent = (clone $userBase)->whereNull('deleted_at')->whereBetween('created_at', [$currentStart, $currentEnd])->count();
            $newUsersPrevious = (clone $userBase)->whereNull('deleted_at')->whereBetween('created_at', [$prevStart, $prevEnd])->count();
        }

        $mauStart = $now->copy()->subDays(30);
        $mauEnd = $now;
        $mauPrevStart = $now->copy()->subDays(60);
        $mauPrevEnd = $now->copy()->subDays(30);

        $mauCurrent = $this->countActiveInRange($userBase, $lastActivitySql, $mauStart, $mauEnd, $isMysql);
        $mauPrevious = $this->countActiveInRange($userBase, $lastActivitySql, $mauPrevStart, $mauPrevEnd, $isMysql);
        if ($isAll) {
            // "Active (all time)" => verified users (not deleted)
            $activeCurrent = (clone $userBase)->whereNull('deleted_at')->whereNotNull('email_verified_at')->count();
            $allStart = $now->copy()->subDays($allWindowDays - 1)->startOfDay();
            $allPrevEnd = $allStart->copy()->subSecond();
            $allPrevStart = $allStart->copy()->subDays($allWindowDays)->startOfDay();
            $activePrevious = (clone $userBase)
                ->whereNull('deleted_at')
                ->whereNotNull('email_verified_at')
                ->where('created_at', '<=', $allPrevEnd)
                ->where(function ($q) use ($allPrevStart) {
                    $q->whereNull('email_verified_at')
                        ->orWhere('email_verified_at', '>=', $allPrevStart);
                })
                ->count();
        } else {
            $activeCurrent = $this->countActiveInRange($userBase, $lastActivitySql, $currentStart, $currentEnd, $isMysql);
            $activePrevious = $this->countActiveInRange($userBase, $lastActivitySql, $prevStart, $prevEnd, $isMysql);
        }

        $totalUsersPrevious = $isAll
            ? (clone $userBase)->where('created_at', '<=', $now->copy()->subDays($allWindowDays))->count()
            : (clone $userBase)->where('created_at', '<=', $prevEnd)->count();

        $minViews = (int) config('admin_push.featured_listing_min_views', 25);
        $postsPerDay = $this->postsPerDay($chartStart, $chartEnd, $chartDayCount, $minViews);

        return [
            'meta' => [
                'period' => $period,
                'current_period_start' => $currentStart->toIso8601String(),
                'current_period_end' => $currentEnd->toIso8601String(),
                'chart_start' => $chartStart->toIso8601String(),
                'chart_end' => $chartEnd->toIso8601String(),
                'generated_at' => $now->toIso8601String(),
            ],
            'user_stats' => $this->buildKpis(
                $totalUsers,
                $totalUsersPrevious,
                $newUsersCurrent,
                $newUsersPrevious,
                $mauCurrent,
                $mauPrevious,
                $activeCurrent,
                $activePrevious,
                $isAll,
                $period,
                $allWindowDays
            ),
            'registration_sources' => $this->registrationSources($userBase, $currentStart, $currentEnd, $isMysql),
            'activity_charts' => [
                'user_growth' => $this->userGrowthSeries($userBase, $chartStart, $chartEnd, $chartDayCount),
                'dau' => $this->dauSeries($userBase, $lastActivitySql, $chartStart, $chartEnd, $chartDayCount, $isMysql),
                'posts_per_day' => $postsPerDay,
            ],
            'post_stats' => $this->listingAggregateStats($minViews),
            'content_stats' => [
                'posts_per_day' => $postsPerDay,
            ],
            'top_categories' => $this->topCategories(10),
            'demographics' => [
                'top_countries' => $this->topCountries(10, $isMysql),
                'operating_systems' => $this->osBreakdown($isMysql),
            ],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon, 4: Carbon, 5: Carbon, 6: int}
     */
    private function resolveTimeWindows(string $period, Carbon $now): array
    {
        $allChartDays = max(1, (int) config('admin_dashboard.all_period_chart_days', 90));

        return match ($period) {
            'today' => (function () use ($now) {
                $curStart = $now->copy()->startOfDay();
                $curEnd = $now->copy();
                $prevStart = $curStart->copy()->subDay();
                $prevEnd = $curStart->copy()->subSecond();
                $chartStart = $curStart->copy();
                $chartEnd = $curEnd->copy();
                $days = 1;

                return [$curStart, $curEnd, $prevStart, $prevEnd, $chartStart, $chartEnd, $days];
            })(),
            'week' => (function () use ($now) {
                $curStart = $now->copy()->subDays(6)->startOfDay();
                $curEnd = $now->copy();
                $prevStart = $curStart->copy()->subDays(7);
                $prevEnd = $curStart->copy()->subSecond();
                $chartStart = $curStart->copy();
                $chartEnd = $curEnd->copy();
                $days = 7;

                return [$curStart, $curEnd, $prevStart, $prevEnd, $chartStart, $chartEnd, $days];
            })(),
            'month' => (function () use ($now) {
                $curStart = $now->copy()->subDays(29)->startOfDay();
                $curEnd = $now->copy();
                $prevStart = $curStart->copy()->subDays(30);
                $prevEnd = $curStart->copy()->subSecond();
                $chartStart = $curStart->copy();
                $chartEnd = $curEnd->copy();
                $days = 30;

                return [$curStart, $curEnd, $prevStart, $prevEnd, $chartStart, $chartEnd, $days];
            })(),
            default => (function () use ($now, $allChartDays) {
                $curStart = Carbon::createFromTimestamp(0, $now->getTimezone());
                $curEnd = $now->copy();
                $prevStart = $curStart->copy();
                $prevEnd = $curStart->copy();
                $chartStart = $now->copy()->subDays($allChartDays - 1)->startOfDay();
                $chartEnd = $now->copy();
                $days = $allChartDays;

                return [$curStart, $curEnd, $prevStart, $prevEnd, $chartStart, $chartEnd, $days];
            })(),
        };
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $userBase
     * @return array<string, array{value: int|float|null, trend_percent: float|null, source?: string, note?: string}>
     */
    private function buildKpis(
        int $totalUsers,
        int $totalUsersPrevious,
        int $newUsersCurrent,
        int $newUsersPrevious,
        int $mauCurrent,
        int $mauPrevious,
        int $activeCurrent,
        int $activePrevious,
        bool $isAllPeriod,
        string $period,
        int $allWindowDays,
    ): array {
        $totalUsersTrend = $this->periodOverPeriodChange($totalUsers, $totalUsersPrevious);

        $newUsersTrend = $this->periodOverPeriodChange($newUsersCurrent, $newUsersPrevious);
        $activeTrend = $isAllPeriod ? null : $this->periodOverPeriodChange($activeCurrent, $activePrevious);

        $activeDescription = match ($period) {
            'today' => 'Daily active users today vs yesterday.',
            'week' => 'Active users in the last 7 days vs previous 7 days.',
            'month' => 'Active users in the last 30 days vs previous 30 days.',
            default => 'All verified users (not deleted).',
        };

        return [
            'total_users' => [
                'value' => $totalUsers,
                'trend_percent' => $totalUsersTrend,
                'description' => 'All users.',
            ],
            'active' => [
                'value' => $activeCurrent,
                'trend_percent' => $activeTrend,
                'description' => $activeDescription,
            ],
            'mau' => [
                'value' => $mauCurrent,
                'trend_percent' => $this->periodOverPeriodChange($mauCurrent, $mauPrevious),
                'description' => 'Monthly active: users with activity in the last 30 days (rolling) vs. prior 30d window (MAU/MAU-1).',
            ],
            'new_users' => [
                'value' => $newUsersCurrent,
                'trend_percent' => $newUsersTrend,
                'description' => $isAllPeriod
                    ? "New signups in last {$allWindowDays} days (vs previous {$allWindowDays} days)."
                    : 'New signups in selected period vs previous equivalent period.',
            ],
            'downloads' => [
                'value' => 0,
                'trend_percent' => null,
                'note' => 'not_tracked',
            ],
            'pending_reports' => [
                'value' => 0,
                'trend_percent' => null,
                'description' => 'Reserved for future implementation.',
            ],
        ];
    }

    private function trendFromPreviousBase(int $delta, int $base): ?float
    {
        if ($base < 1) {
            return $delta > 0 ? 100.0 : 0.0;
        }

        return round($delta / $base * 100, 1);
    }

    private function periodOverPeriodChange(int $current, int $previous): ?float
    {
        if ($previous < 1) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    private function lastUserActivityExpression(bool $isMysql): string
    {
        if (! $isMysql) {
            return 'COALESCE(last_login_at, updated_at)';
        }

        return "COALESCE(
        last_login_at,
        FROM_UNIXTIME(CAST(JSON_EXTRACT(data, '$.lastLoginAt._seconds') AS UNSIGNED)),
        FROM_UNIXTIME(CAST(JSON_EXTRACT(data, '$.lastSignInTime._seconds') AS UNSIGNED)),
        updated_at
    )";
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $userBase
     */
    private function countActiveInRange($userBase, string $lastActivitySql, Carbon $start, Carbon $end, bool $isMysql): int
    {
        if (! $isMysql) {
            return (clone $userBase)
                ->where(function ($w) use ($start, $end) {
                    $w->whereBetween('last_login_at', [$start, $end])
                        ->orWhere(function ($f) use ($start, $end) {
                            $f->whereNull('last_login_at')
                                ->whereBetween('updated_at', [$start, $end]);
                        });
                })
                ->count();
        }

        $sql = "($lastActivitySql) between ? and ?";

        return (clone $userBase)
            ->whereRaw($sql, [$start, $end])
            ->count();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $userBase
     * @return list<array{date: string, label: string, cumulative_users: int, new_signups: int}>
     */
    private function userGrowthSeries($userBase, Carbon $chartStart, Carbon $chartEnd, int $chartDayCount): array
    {
        $out = [];
        for ($i = 0; $i < $chartDayCount; $i++) {
            $day = $chartStart->copy()->addDays($i);
            if ($day->greaterThan($chartEnd)) {
                break;
            }
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();
            if ($dayEnd->greaterThan($chartEnd)) {
                $dayEnd = $chartEnd->copy();
            }
            $cumulative = (clone $userBase)->where('created_at', '<=', $dayEnd)->count();
            $new = (clone $userBase)
                ->where('created_at', '<=', $dayEnd)
                ->where('created_at', '>=', $dayStart)
                ->count();
            $out[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'cumulative_users' => $cumulative,
                'new_signups' => $new,
            ];
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $userBase
     * @return list<array{date: string, label: string, dau: int}>
     */
    private function dauSeries($userBase, string $lastActivitySql, Carbon $chartStart, Carbon $chartEnd, int $chartDayCount, bool $isMysql): array
    {
        $out = [];
        for ($i = 0; $i < $chartDayCount; $i++) {
            $day = $chartStart->copy()->addDays($i);
            if ($day->greaterThan($chartEnd)) {
                break;
            }
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();
            if ($dayEnd->greaterThan($chartEnd)) {
                $dayEnd = $chartEnd->copy();
            }

            if (! $isMysql) {
                $count = (clone $userBase)
                    ->whereBetween('updated_at', [$dayStart, $dayEnd])
                    ->count();
            } else {
                $count = (clone $userBase)
                    ->whereRaw("($lastActivitySql) between ? and ?", [$dayStart, $dayEnd])
                    ->count();
            }
            $out[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'dau' => $count,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{date: string, label: string, totals: int, featured: int}>
     */
    private function postsPerDay(Carbon $chartStart, Carbon $chartEnd, int $chartDayCount, int $minViews): array
    {
        $out = [];
        for ($i = 0; $i < $chartDayCount; $i++) {
            $day = $chartStart->copy()->addDays($i);
            if ($day->greaterThan($chartEnd)) {
                break;
            }
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();
            if ($dayEnd->greaterThan($chartEnd)) {
                $dayEnd = $chartEnd->copy();
            }
            $totals = Listing::query()
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->count();
            $featured = Listing::query()
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->where('views_count', '>=', $minViews)
                ->count();
            $out[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'totals' => $totals,
                'featured' => $featured,
            ];
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $userBase
     * @return array{organic: array{count: int, percent: float}, referral: array{count: int, percent: float}, paid_ads: array{count: int, percent: float}, unlabeled: int, total: int, note: string}
     */
    private function registrationSources($userBase, Carbon $from, Carbon $to, bool $isMysql): array
    {
        $rows = (clone $userBase)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'data', 'created_at']);
        $organic = 0;
        $referral = 0;
        $paid = 0;
        $unlabeled = 0;
        foreach ($rows as $u) {
            $raw = null;
            if (is_array($u->data)) {
                $raw = $u->data['registrationSource']
                    ?? data_get($u->data, 'signUpInfo.registrationSource');
            }
            if (! is_string($raw) && ! is_numeric($raw)) {
                $unlabeled++;

                continue;
            }
            $s = strtolower(trim((string) $raw));
            if ($s === '' || in_array($s, ['null', 'unknown', 'n/a'], true)) {
                $unlabeled++;

                continue;
            }
            if (str_contains($s, 'refer')) {
                $referral++;
            } elseif (str_contains($s, 'paid') || str_contains($s, 'ad')) {
                $paid++;
            } else {
                $organic++;
            }
        }
        $total = $organic + $referral + $paid + $unlabeled;
        $pc = function (int $c) use ($total): array {
            return [
                'count' => $c,
                'percent' => $total > 0 ? round($c / $total * 100, 1) : 0.0,
            ];
        };

        return [
            'organic' => $pc($organic),
            'referral' => $pc($referral),
            'paid_ads' => $pc($paid),
            'unlabeled' => $unlabeled,
            'total' => $total,
            'note' => $isMysql
                ? 'registrationSource in users.data (or signUpInfo.registrationSource) among signups in the current period; unlabeled = missing or unmapped source.'
                : 'SQLite/development: registration sources derived in PHP; production MySQL is recommended for JSON-native queries.',
        ];
    }

    /**
     * @return array{total: int, active: int, sold_or_inactive: int, expired: int, reported: int, featured: int, availability_note: string, featured_min_views: int}
     */
    private function listingAggregateStats(int $minViews): array
    {
        $q = Listing::query()->whereNull('deleted_at');
        $total = (clone $q)->count();
        $active = (clone $q)->where('availability', true)->count();
        $inactive = (clone $q)->where('availability', false)->count();
        $featured = (clone $q)->where('views_count', '>=', $minViews)->count();
        $reported = (int) DB::table('listing_reports')
            ->selectRaw('COUNT(DISTINCT listing_id) as c')
            ->value('c');
        $expired = 0;

        return [
            'total' => $total,
            'active' => $active,
            'sold_or_inactive' => $inactive,
            'expired' => $expired,
            'reported' => $reported,
            'featured' => $featured,
            'availability_note' => 'active=availability true; sold_or_inactive=availability false; reported=unique listings with any listing_reports row; expired=reserved (no end-date field in DB yet); featured=views_count >= min.',
            'featured_min_views' => $minViews,
        ];
    }

    /**
     * @return list<array{name: string, listings_count: int, category_id: int|null}>
     */
    private function topCategories(int $limit): array
    {
        $rows = DB::table('listings as l')
            ->join('categories as c', 'c.id', '=', 'l.service_category')
            ->whereNull('l.deleted_at')
            ->whereNotNull('l.service_category')
            ->groupBy('c.id', 'c.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit)
            ->get([DB::raw('c.id as category_id'), 'c.name as name', DB::raw('COUNT(*) as cnt')]);

        return $rows->map(fn ($r) => [
            'name' => $r->name,
            'listings_count' => (int) $r->cnt,
            'category_id' => (int) $r->category_id,
        ])->all();
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function topCountries(int $limit, bool $isMysql): array
    {
        if (! $isMysql) {
            return $this->topCountriesSlow($limit);
        }
        $rows = DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotNull('location')
            ->selectRaw("COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.country')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.countryName')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.country_name')), '')
            ) as c")
            ->get();

        $counts = [];
        foreach ($rows as $r) {
            if (! $r->c) {
                continue;
            }
            $label = (string) $r->c;
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts);
        $i = 0;
        $out = [];
        foreach ($counts as $name => $cnt) {
            $out[] = ['label' => $name, 'count' => (int) $cnt];
            $i++;
            if ($i >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function topCountriesSlow(int $limit): array
    {
        $counts = [];
        User::query()->whereNull('deleted_at')->orderBy('id')
            ->chunk(500, function (Collection $chunk) use (&$counts) {
                foreach ($chunk as $u) {
                    $loc = is_array($u->location) ? $u->location : [];
                    $c = $loc['country'] ?? $loc['countryName'] ?? $loc['country_name'] ?? null;
                    if (is_string($c) && $c !== '') {
                        $counts[$c] = ($counts[$c] ?? 0) + 1;
                    }
                }
            });
        arsort($counts);
        $out = [];
        $i = 0;
        foreach ($counts as $label => $cnt) {
            $out[] = ['label' => $label, 'count' => (int) $cnt];
            $i++;
            if ($i >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{os: string, count: int, percent: float}>
     */
    private function osBreakdown(bool $isMysql): array
    {
        if (! $isMysql) {
            return $this->osBreakdownSlow();
        }
        $android = User::query()
            ->whereNull('deleted_at')
            ->where(function ($w) {
                $w->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.platform\'))) = ?', ['android'])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.device_platform\'))) = ?', ['android'])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.deviceType\'))) = ?', ['android']);
            })->count();
        $ios = User::query()
            ->whereNull('deleted_at')
            ->where(function ($w) {
                $w->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.platform\'))) in (?, ?)', ['ios', 'iphone'])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.device_platform\'))) = ?', ['ios'])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.deviceType\'))) in (?, ?)', ['ios', 'iphone']);
            })->count();
        $all = max(1, User::query()->whereNull('deleted_at')->count());
        $other = $all - $android - $ios;
        if ($other < 0) {
            $other = 0;
        }
        $pct = fn (int $n) => round($n / $all * 100, 1);

        return [
            ['os' => 'android', 'count' => $android, 'percent' => $pct($android)],
            ['os' => 'ios', 'count' => $ios, 'percent' => $pct($ios)],
            ['os' => 'unknown', 'count' => $other, 'percent' => $pct($other)],
        ];
    }

    /**
     * @return list<array{os: string, count: int, percent: float}>
     */
    private function osBreakdownSlow(): array
    {
        $android = 0;
        $ios = 0;
        $total = 0;
        User::query()->whereNull('deleted_at')->orderBy('id')
            ->chunk(500, function (Collection $chunk) use (&$android, &$ios, &$total) {
                foreach ($chunk as $u) {
                    $total++;
                    $d = is_array($u->data) ? $u->data : [];
                    $p = strtolower((string) ($d['platform'] ?? $d['device_platform'] ?? $d['deviceType'] ?? ''));
                    if (str_contains($p, 'android')) {
                        $android++;

                        continue;
                    }
                    if ($p === 'ios' || $p === 'iphone' || str_contains($p, 'ios')) {
                        $ios++;

                        continue;
                    }
                }
            });
        if ($total < 1) {
            return [
                ['os' => 'android', 'count' => 0, 'percent' => 0.0],
                ['os' => 'ios', 'count' => 0, 'percent' => 0.0],
                ['os' => 'unknown', 'count' => 0, 'percent' => 0.0],
            ];
        }
        $unk = $total - $android - $ios;
        if ($unk < 0) {
            $unk = 0;
        }
        $pct = fn (int $n) => round($n / $total * 100, 1);

        return [
            ['os' => 'android', 'count' => $android, 'percent' => $pct($android)],
            ['os' => 'ios', 'count' => $ios, 'percent' => $pct($ios)],
            ['os' => 'unknown', 'count' => $unk, 'percent' => $pct($unk)],
        ];
    }
}
