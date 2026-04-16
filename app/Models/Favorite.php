<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class Favorite extends Pivot
{
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'favorites';

    /**
     * @var string[]
     */
    protected $fillable = [
      'user_id',
      'listing_id',
      'firebase_id'
    ];
}
