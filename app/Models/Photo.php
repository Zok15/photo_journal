<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Photo extends Model
{
    protected $fillable = ['series_id', 'path', 'original_name', 'size', 'mime', 'sort_order'];

    public function series()
    {
        return $this->belongsTo(Series::class);
    }
}
