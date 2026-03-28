<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    /**
     * Get global app settings.
     *
     * @return JsonResponse
     */
    public function getAppSettings(): JsonResponse
    {
        $settings = AppSetting::firstOrCreate([], [
            'maintenance_mode' => false,
            'normal_update'    => false,
            'force_update'     => false,
        ]);

        return $this->actionSuccess('settings_retrieved', $settings);
    }

    /**
     * Update global app settings.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function updateAppSettings(Request $request): JsonResponse
    {
        $settings = AppSetting::first();
        
        if (!$settings) {
            $settings = new AppSetting();
        }

        $settings->fill($request->only([
            'maintenance_mode',
            'normal_update',
            'force_update',
        ]));
        
        $settings->save();

        return $this->actionSuccess('settings_updated', $settings);
    }
}
