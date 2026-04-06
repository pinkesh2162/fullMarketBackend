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
    /**
     * Get global app settings.
     *
     * @return JsonResponse
     */
    public function getAppSettings(): JsonResponse
    {
        $settings = AppSetting::firstOrCreate([], [
            'maintenance_mode'        => false,
            'maintenance_title'       => 'Maintenance Underway',
            'maintenance_message'     => 'We are currently performing scheduled maintenance. Please check back soon.',
            'min_version_android'     => '1.0.0',
            'latest_version_android'  => '1.0.0',
            'android_store_url'       => 'https://play.google.com/store/apps/details?id=fullmarket.app',
            'min_version_ios'         => '1.0.0',
            'latest_version_ios'      => '1.0.0',
            'ios_store_url'           => 'https://apps.apple.com/app/id123456789',
            'force_update_below_min'  => true,
            'enabled_location_filter' => false,
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
        $validated = $request->validate([
            'maintenance_mode'        => 'boolean',
            'maintenance_title'       => 'nullable|string',
            'maintenance_message'     => 'nullable|string',
            'min_version_android'     => 'string|regex:/^\d+\.\d+(\.\d+)?$/',
            'latest_version_android'  => 'string|regex:/^\d+\.\d+(\.\d+)?$/',
            'android_store_url'       => 'nullable|url',
            'min_version_ios'         => 'string|regex:/^\d+\.\d+(\.\d+)?$/',
            'latest_version_ios'      => 'string|regex:/^\d+\.\d+(\.\d+)?$/',
            'ios_store_url'           => 'nullable|url',
            'force_update_below_min'  => 'boolean',
            'release_notes'           => 'nullable|string',
            'enabled_location_filter' => 'boolean',
        ]);

        $settings = AppSetting::first();
        
        if (!$settings) {
            $settings = new AppSetting();
        }

        $settings->fill($validated);
        $settings->save();

        return $this->actionSuccess('settings_updated', $settings);
    }
}
