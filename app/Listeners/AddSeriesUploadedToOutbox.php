<?php

namespace App\Listeners;

use App\Events\SeriesUploaded;
use App\Models\OutboxEvent;

class AddSeriesUploadedToOutbox
{
    public function handle(SeriesUploaded $event): void
    {
        OutboxEvent::create([
            'type' => 'series.uploaded',
            'payload' => [
                'series_id' => $event->series->id,
                'title' => $event->series->title,
            ],
        ]);
    }
}
