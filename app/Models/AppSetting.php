<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_mode',
        'normal_update',
        'force_update',
    ];

    protected $casts = [
        'maintenance_mode' => 'boolean',
        'normal_update'    => 'boolean',
        'force_update'     => 'boolean',
    ];
}
