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

        // TODO:
        // - генерация превью
        // - анализ EXIF
        // - AI-теги / рекомендации (позже)

        event(new SeriesUploaded($series));
    }
}