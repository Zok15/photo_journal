<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DispatchOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $outboxEventId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            /** @var OutboxEvent $event */
            $event = OutboxEvent::query()
                ->lockForUpdate()
                ->findOrFail($this->outboxEventId);

            if ($event->status === 'done') {
                return; // идемпотентность
            }

            $event->update([
                'status' => 'processing',
                'attempts' => $event->attempts + 1,
            ]);



            $event->update([
                'status' => 'done',
                'processed_at' => now(),
                'last_error' => null,
            ]);
        });
    }

    public function failed(\Throwable $e): void
    {
        OutboxEvent::query()
            ->whereKey($this->outboxEventId)
            ->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
                'available_at' => Carbon::now()->addMinutes(5),
            ]);
    }
}
