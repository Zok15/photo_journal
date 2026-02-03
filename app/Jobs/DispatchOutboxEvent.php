<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DispatchOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $outboxEventId) {}

    public function handle(): void
    {
        // We fetch + lock one outbox row in a transaction to ensure:
        // 1) only one worker updates this row at a time;
        // 2) state transitions remain consistent (pending -> processing -> done/failed).
        $dispatchContext = DB::transaction(function () {
            /** @var OutboxEvent $event */
            $event = OutboxEvent::query()
                ->lockForUpdate()
                ->findOrFail($this->outboxEventId);

            if (in_array($event->status, ['done', 'failed'], true)) {
                // Idempotency guard: once terminal state is reached,
                // repeated job execution must do nothing.
                return null;
            }

            if ($event->attempts >= $this->maxAttempts()) {
                $event->update([
                    'status' => 'failed',
                    'available_at' => null,
                ]);

                return null;
            }

            $attempt = $event->attempts + 1;

            $event->update([
                'status' => 'processing',
                'attempts' => $attempt,
                'last_error' => null,
            ]);

            return [
                'event_id' => $event->id,
                'event_key' => $event->integrationEventKey(),
                'type' => $event->type,
                'payload' => (array) $event->payload,
                'attempt' => $attempt,
            ];
        });

        if ($dispatchContext === null) {
            return;
        }

        try {
            $this->dispatchToIntegration(
                $dispatchContext['event_id'],
                $dispatchContext['event_key'],
                $dispatchContext['type'],
                $dispatchContext['payload'],
            );

            OutboxEvent::query()
                ->whereKey($this->outboxEventId)
                ->update([
                    'status' => 'done',
                    'processed_at' => now(),
                    'available_at' => null,
                    'last_error' => null,
                ]);
        } catch (Throwable $e) {
            $this->markRetryOrFailed($dispatchContext['attempt'], $e->getMessage());
        }
    }

    public function failed(Throwable $e): void
    {
        $event = OutboxEvent::query()->find($this->outboxEventId);

        if ($event === null || in_array($event->status, ['done', 'failed'], true)) {
            return;
        }

        $attempt = max(1, (int) $event->attempts);
        $this->markRetryOrFailed($attempt, $e->getMessage());
    }

    private function dispatchToIntegration(
        int $eventId,
        string $eventKey,
        string $type,
        array $payload
    ): void {
        // This method is the future integration boundary.
        // When real webhook/AI adapters are added, we will call them here
        // and always pass event_id + event_key for idempotency on receiver side.
        //
        // event_id  - local immutable integer identifier.
        // event_key - stable external idempotency key (type + id).
        //             Consumers can safely deduplicate by this key.

        // Optional assertion hook for tests:
        // proves that event_key is calculated and forwarded correctly.
        if (isset($payload['assert_event_key']) && $payload['assert_event_key'] !== $eventKey) {
            throw new RuntimeException('Unexpected event key passed to integration.');
        }

        // Temporary simulation hook for tests/dev until real integrations are added.
        if (($payload['simulate_fail'] ?? false) === true) {
            $message = $payload['simulate_fail_message'] ?? 'Simulated integration failure.';
            throw new RuntimeException($message);
        }

        // Mark arguments as intentionally consumed to keep static analyzers happy
        // until a real integration implementation is introduced.
        $unused = [$eventId, $eventKey, $type];
        unset($unused);
    }

    private function markRetryOrFailed(int $attempt, string $errorMessage): void
    {
        $status = $attempt >= $this->maxAttempts() ? 'failed' : 'pending';
        $availableAt = $status === 'pending'
            ? now()->addSeconds($this->calculateBackoffSeconds($attempt))
            : null;

        OutboxEvent::query()
            ->whereKey($this->outboxEventId)
            ->update([
                'status' => $status,
                'available_at' => $availableAt,
                'processed_at' => null,
                'last_error' => $errorMessage,
            ]);
    }

    private function calculateBackoffSeconds(int $attempt): int
    {
        // Exponential backoff:
        // attempt=1 => base
        // attempt=2 => base*2
        // attempt=3 => base*4
        // ...
        $seconds = $this->baseBackoffSeconds() * (2 ** max(0, $attempt - 1));

        return min($seconds, $this->maxBackoffSeconds());
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('outbox.retry.max_attempts', 5));
    }

    private function baseBackoffSeconds(): int
    {
        return max(1, (int) config('outbox.retry.base_backoff_seconds', 60));
    }

    private function maxBackoffSeconds(): int
    {
        return max(1, (int) config('outbox.retry.max_backoff_seconds', 3600));
    }
}
