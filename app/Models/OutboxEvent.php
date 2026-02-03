<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    protected $fillable = [
        'type', 'payload', 'status', 'attempts', 'available_at', 'processed_at', 'last_error'
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function integrationEventKey(): string
    {
        // Deterministic key for idempotent integrations:
        // the same outbox row always produces the same external key.
        return "{$this->type}:{$this->id}";
    }
}
