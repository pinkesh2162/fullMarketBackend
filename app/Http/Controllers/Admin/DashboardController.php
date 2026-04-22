<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\Admin\AdminDashboardRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected AdminDashboardRepository $dashboardRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $period = (string) $request->query('period', 'week');
        if (! in_array($period, ['today', 'week', 'month', 'all'], true)) {
            return $this->validationFailed('Invalid period. Use today, week, month, or all.', [
                'period' => ['The period must be one of: today, week, month, all.'],
            ]);
        }

        $data = $this->dashboardRepository->getDashboard($period);

        return $this->actionSuccess('admin_dashboard_fetched', $data, self::HTTP_OK, [
            'period' => $period,
        ]);
    }
}
