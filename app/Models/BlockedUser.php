<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlockedUser extends Model
{

    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = ['blocker_id', 'blocker_type', 'blocked_id', 'blocked_type'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function blocker()
    {
        return $this->morphTo();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function blocked()
    {
        return $this->morphTo();
    }
}
