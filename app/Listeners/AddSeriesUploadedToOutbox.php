<?php

namespace App\Listeners;

use App\Events\SeriesUploaded;
use App\Models\OutboxEvent;

/**
 * Слушатель доменного события, который пишет запись в outbox.
 */
class AddSeriesUploadedToOutbox
{
    public function handle(SeriesUploaded $event): void
    {
        // Эта запись позже будет доставлена интеграционными джобами.
        OutboxEvent::create([
            'type' => 'series.uploaded',
            'payload' => [
                'series_id' => $event->series->id,
                'title' => $event->series->title,
            ],
        ]);
    }
}
