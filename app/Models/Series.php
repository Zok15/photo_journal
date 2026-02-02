<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Series extends Model
{
    protected $fillable = ['user_id', 'title', 'description'];

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }
}
