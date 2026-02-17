<?php

namespace App\Console\Commands;

use App\Jobs\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Команда-поллер outbox.
 * Забирает pending-события и ставит джобы доставки в очередь.
 */
class OutboxPoll extends Command
{
    protected $signature = 'outbox:poll {--limit=50}';

    protected $description = 'Dispatch pending outbox events to queue';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        // В транзакции "резервируем" пачку событий, чтобы воркеры не пересекались.
        $claimedIds = DB::transaction(function () use ($limit) {
            $ids = OutboxEvent::query()
                ->where('status', 'pending')
                ->where(function ($query) {
                    $query
                        ->whereNull('available_at')
                        ->orWhere('available_at', '<=', now());
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($limit)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return collect();
            }

            OutboxEvent::query()
                ->whereIn('id', $ids->all())
                ->where('status', 'pending')
                ->update([
                    'status' => 'processing',
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => null,
                ]);

            return OutboxEvent::query()
                ->whereIn('id', $ids->all())
                ->where('status', 'processing')
                ->orderBy('id')
                ->pluck('id');
        });

        foreach ($claimedIds as $eventId) {
            // Каждое событие уходит в отдельную джобу для ретраев и изоляции ошибок.
            DispatchOutboxEvent::dispatch((int) $eventId);
        }

        $this->info("Dispatched {$claimedIds->count()} outbox events.");

        return self::SUCCESS;
    }
}
