<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model {
    protected $fillable = [
        'type', 'payload', 'status', 'attempts', 'available_at', 'processed_at', 'last_error'
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}