<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'hide_ads',
        'notification_post',
        'message_notification',
        'business_create',
        'follow_request',
        'notification_time_start',
        'notification_time_end',
    ];

    protected $casts = [
        'hide_ads' => 'boolean',
        'notification_post' => 'boolean',
        'message_notification' => 'boolean',
        'business_create' => 'boolean',
        'follow_request' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
