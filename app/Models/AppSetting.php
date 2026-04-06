<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_mode',
        'maintenance_title',
        'maintenance_message',
        'min_version_android',
        'latest_version_android',
        'android_store_url',
        'min_version_ios',
        'latest_version_ios',
        'ios_store_url',
        'force_update_below_min',
        'release_notes',
        'enabled_location_filter',
    ];

    protected $casts = [
        'maintenance_mode'        => 'boolean',
        'force_update_below_min'  => 'boolean',
        'enabled_location_filter' => 'boolean',
    ];
}
