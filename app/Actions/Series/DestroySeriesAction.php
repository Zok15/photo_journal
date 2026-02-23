<?php

namespace App\Actions\Series;

use App\Models\Series;
use App\Services\Series\SeriesCacheService;
use Illuminate\Support\Facades\Storage;

class DestroySeriesAction
{
    public function __construct(private SeriesCacheService $seriesCacheService)
    {
    }

    public function execute(Series $series, string $disk): void
    {
        $photoPaths = $series->photos()
            ->pluck('path')
            ->filter()
            ->values()
            ->all();

        $series->delete();

        if (! empty($photoPaths)) {
            Storage::disk($disk)->delete($photoPaths);
        }

        $this->seriesCacheService->invalidate((int) $series->user_id, (int) $series->id);
    }
}
