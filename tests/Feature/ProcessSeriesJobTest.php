<?php

namespace Tests\Feature;

use App\Jobs\ProcessSeries;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessSeriesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_series_keeps_event_flow_when_feature_flags_disabled(): void
    {
        config()->set('photo_processing.preview_enabled', false);
        config()->set('photo_processing.exif_enabled', false);

        $series = Series::query()->create([
            'title' => 'Flagged flow',
            'description' => 'No-op steps',
        ]);

        (new ProcessSeries($series->id))->handle();

        $this->assertDatabaseHas('outbox_events', [
            'type' => 'series.uploaded',
            'status' => 'pending',
        ]);
    }
}
