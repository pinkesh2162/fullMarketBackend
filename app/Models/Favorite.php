<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class Favorite extends Pivot
{
    use SoftDeletes;


    protected $table = 'favorites';

    protected $fillable = [
      'user_id',
      'listing_id'
    ];
}
