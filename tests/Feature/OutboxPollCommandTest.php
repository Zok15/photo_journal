<?php

namespace Tests\Feature;

use App\Jobs\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboxPollCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_list_contains_outbox_poll_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('outbox:poll')
            ->assertExitCode(0);
    }

    public function test_outbox_poll_dispatches_only_due_pending_events(): void
    {
        Queue::fake();

        $dueWithoutDelay = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 1],
            'status' => 'pending',
            'available_at' => null,
        ]);

        $dueWithDelay = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 2],
            'status' => 'pending',
            'available_at' => now()->subMinute(),
        ]);

        $future = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 3],
            'status' => 'pending',
            'available_at' => now()->addMinute(),
        ]);

        $done = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 4],
            'status' => 'done',
        ]);

        $failed = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 5],
            'status' => 'failed',
        ]);

        $this->artisan('outbox:poll --limit=50')
            ->expectsOutput('Dispatched 2 outbox events.')
            ->assertExitCode(0);

        Queue::assertPushed(DispatchOutboxEvent::class, 2);
        Queue::assertPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job) => $job->outboxEventId === $dueWithoutDelay->id);
        Queue::assertPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job) => $job->outboxEventId === $dueWithDelay->id);
        Queue::assertNotPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job) => $job->outboxEventId === $future->id);
        Queue::assertNotPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job) => $job->outboxEventId === $done->id);
        Queue::assertNotPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job) => $job->outboxEventId === $failed->id);
    }
}
