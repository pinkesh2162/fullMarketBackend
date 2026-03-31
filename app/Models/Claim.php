<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Claim extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'user_id',
        'listing_id',
        'claim_type',
        'full_name',
        'email',
        'phone',
        'phone_code',
        'description',
    ];

    const CLAIM_IMAGES = 'claim_images';

    //claim_type
    const CLAIM = 1;
    const REMOVE_AD = 0;
}
