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

        $this->assertDatabaseHas('outbox_events', [
            'id' => $dueWithoutDelay->id,
            'status' => 'processing',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $dueWithDelay->id,
            'status' => 'processing',
            'attempts' => 1,
        ]);
    }

    public function test_outbox_poll_does_not_claim_same_event_twice_in_parallel_runs(): void
    {
        Queue::fake();

        $event = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 7],
            'status' => 'pending',
            'available_at' => null,
        ]);

        $this->artisan('outbox:poll --limit=50')
            ->expectsOutput('Dispatched 1 outbox events.')
            ->assertExitCode(0);

        $this->artisan('outbox:poll --limit=50')
            ->expectsOutput('Dispatched 0 outbox events.')
            ->assertExitCode(0);

        Queue::assertPushed(DispatchOutboxEvent::class, 1);
        Queue::assertPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job) => $job->outboxEventId === $event->id);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $event->id,
            'status' => 'processing',
            'attempts' => 1,
        ]);
    }

    public function test_outbox_poll_recovers_stale_processing_event_and_dispatches_it_again(): void
    {
        Queue::fake();
        config()->set('outbox.retry.processing_stale_seconds', 60);

        $staleProcessing = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 8],
            'status' => 'processing',
            'attempts' => 1,
        ]);
        $staleProcessing->forceFill(['updated_at' => now()->subMinutes(3)])->saveQuietly();

        $freshProcessing = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 9],
            'status' => 'processing',
            'attempts' => 1,
        ]);
        $freshProcessing->forceFill(['updated_at' => now()->subSeconds(20)])->saveQuietly();

        $this->artisan('outbox:poll --limit=50')
            ->expectsOutput('Dispatched 1 outbox events.')
            ->assertExitCode(0);

        Queue::assertPushed(DispatchOutboxEvent::class, 1);
        Queue::assertPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job) => $job->outboxEventId === $staleProcessing->id);
        Queue::assertNotPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job) => $job->outboxEventId === $freshProcessing->id);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $staleProcessing->id,
            'status' => 'processing',
            'attempts' => 2,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $freshProcessing->id,
            'status' => 'processing',
            'attempts' => 1,
        ]);
    }

    public function test_outbox_poll_marks_stale_processing_event_failed_when_retry_limit_reached(): void
    {
        Queue::fake();
        config()->set('outbox.retry.processing_stale_seconds', 60);
        config()->set('outbox.retry.max_attempts', 2);

        $staleAtLimit = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 10],
            'status' => 'processing',
            'attempts' => 2,
        ]);
        $staleAtLimit->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();

        $this->artisan('outbox:poll --limit=50')
            ->expectsOutput('Dispatched 0 outbox events.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('outbox_events', [
            'id' => $staleAtLimit->id,
            'status' => 'failed',
            'attempts' => 2,
        ]);
    }
}
