<?php

namespace App\Console\Commands;

use App\Jobs\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;

class OutboxPoll extends Command
{
    protected $signature = 'outbox:poll {--limit=50}';
    protected $description = 'Dispatch pending outbox events to queue';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $events = OutboxEvent::query()
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('available_at')
                  ->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            DispatchOutboxEvent::dispatch($event->id);
        }

        $this->info("Dispatched {$events->count()} outbox events.");

        return self::SUCCESS;
    }
}
