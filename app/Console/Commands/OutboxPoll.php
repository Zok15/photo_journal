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
        $this->recoverStaleProcessingEvents();

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

    private function recoverStaleProcessingEvents(): void
    {
        $staleSeconds = $this->staleProcessingSeconds();
        $staleBefore = now()->subSeconds($staleSeconds);
        $maxAttempts = $this->maxAttempts();

        OutboxEvent::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', $staleBefore)
            ->where('attempts', '>=', $maxAttempts)
            ->update([
                'status' => 'failed',
                'available_at' => null,
                'last_error' => 'Outbox event became stale in processing and reached max attempts.',
            ]);

        OutboxEvent::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', $staleBefore)
            ->where('attempts', '<', $maxAttempts)
            ->update([
                'status' => 'pending',
                'available_at' => null,
                'last_error' => 'Recovered stale processing event for retry.',
            ]);
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('outbox.retry.max_attempts', 5));
    }

    private function staleProcessingSeconds(): int
    {
        return max(60, (int) config('outbox.retry.processing_stale_seconds', 900));
    }
}
