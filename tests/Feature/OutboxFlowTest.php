<?php

namespace Tests\Feature;

use App\Events\SeriesUploaded;
use App\Jobs\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboxFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_uploaded_event_is_written_to_outbox(): void
    {
        $series = Series::query()->create([
            'title' => 'Sunrise shoot',
            'description' => 'Early morning session',
        ]);

        event(new SeriesUploaded($series));

        $this->assertDatabaseHas('outbox_events', [
            'type' => 'series.uploaded',
            'status' => 'pending',
        ]);
    }

    public function test_dispatch_outbox_event_marks_event_done(): void
    {
        $event = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => ['series_id' => 1],
        ]);

        (new DispatchOutboxEvent($event->id))->handle();
        $event->refresh();

        $this->assertSame('done', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->last_error);
    }

    public function test_dispatch_outbox_event_failure_returns_event_to_pending_with_backoff(): void
    {
        $event = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => [
                'series_id' => 1,
                'simulate_fail' => true,
                'simulate_fail_message' => 'integration is down',
            ],
        ]);

        (new DispatchOutboxEvent($event->id))->handle();
        $event->refresh();

        $this->assertSame('pending', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('integration is down', $event->last_error);
        $this->assertNotNull($event->available_at);
        $this->assertTrue($event->available_at->isFuture());
    }

    public function test_dispatch_outbox_event_marks_event_failed_after_max_attempts(): void
    {
        $event = OutboxEvent::query()->create([
            'type' => 'series.uploaded',
            'payload' => [
                'series_id' => 1,
                'simulate_fail' => true,
                'simulate_fail_message' => 'still down',
            ],
            'attempts' => DispatchOutboxEvent::MAX_ATTEMPTS - 1,
        ]);

        (new DispatchOutboxEvent($event->id))->handle();
        $event->refresh();

        $this->assertSame('failed', $event->status);
        $this->assertSame(DispatchOutboxEvent::MAX_ATTEMPTS, $event->attempts);
        $this->assertSame('still down', $event->last_error);
        $this->assertNull($event->available_at);
    }
}
