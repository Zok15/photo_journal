<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Photo extends Model
{
    protected $fillable = ['series_id', 'path', 'original_name', 'size', 'mime'];

    public function series()
    {
        return $this->belongsTo(Series::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}