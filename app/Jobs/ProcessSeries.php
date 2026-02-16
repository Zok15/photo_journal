<?php

namespace App\Jobs;

use App\Events\SeriesUploaded;
use App\Models\Series;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSeries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $seriesId) {}

    public function handle(): void
    {
        $series = Series::query()->findOrFail($this->seriesId);

        if ((bool) config('photo_processing.preview_enabled', true)) {
            $this->generatePreviews($series);
        }

        if ((bool) config('photo_processing.exif_enabled', true)) {
            $this->extractExif($series);
        }

        event(new SeriesUploaded($series));
    }

    private function generatePreviews(Series $series): void
    {
        // Placeholder for staged preview generation rollout.
        unset($series);
    }

    private function extractExif(Series $series): void
    {
        // Placeholder for staged EXIF extraction rollout.
        unset($series);
    }
}
