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

    public const MAX_ATTEMPTS = 5;
    public const BASE_BACKOFF_SECONDS = 60;
    public const MAX_BACKOFF_SECONDS = 3600;

    public function __construct(public int $outboxEventId) {}

    public function handle(): void
    {
        $dispatchContext = DB::transaction(function () {
            /** @var OutboxEvent $event */
            $event = OutboxEvent::query()
                ->lockForUpdate()
                ->findOrFail($this->outboxEventId);

            if (in_array($event->status, ['done', 'failed'], true)) {
                return null; // идемпотентность
            }

            if ($event->attempts >= self::MAX_ATTEMPTS) {
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

    private function dispatchToIntegration(string $type, array $payload): void
    {
        // Temporary simulation hook for tests/dev until real integrations are added.
        if (($payload['simulate_fail'] ?? false) === true) {
            $message = $payload['simulate_fail_message'] ?? 'Simulated integration failure.';
            throw new RuntimeException($message);
        }
    }

    private function markRetryOrFailed(int $attempt, string $errorMessage): void
    {
        $status = $attempt >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
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
        $seconds = self::BASE_BACKOFF_SECONDS * (2 ** max(0, $attempt - 1));

        return min($seconds, self::MAX_BACKOFF_SECONDS);
    }
}
