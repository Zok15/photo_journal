<?php

namespace App\Actions\SeriesPhoto;

use App\Models\Photo;
use App\Models\Series;
use App\Services\Series\SeriesCacheService;
use Illuminate\Support\Facades\Storage;

class DestroySeriesPhotoAction
{
    public function __construct(private SeriesCacheService $seriesCacheService)
    {
    }

    public function execute(Series $series, Photo $photo, string $disk): void
    {
        $paths = array_values(array_filter([
            $photo->path,
            $photo->preview_path,
        ]));
        Storage::disk($disk)->delete($paths);

        $photo->delete();
        $this->seriesCacheService->touchForConditionalCache($series);

        if (! $series->photos()->exists()) {
            $series->tags()->detach();
        }

        $this->seriesCacheService->invalidateForSeries($series);
    }
}
