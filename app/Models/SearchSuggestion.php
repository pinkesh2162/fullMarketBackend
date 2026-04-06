<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchSuggestion extends Model
{
    use HasFactory;

    protected $fillable = ['term', 'hits'];
}
