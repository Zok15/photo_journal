<?php

namespace App\Services\Series;

use App\Models\Photo;
use App\Models\Series;

class SeriesPhotoOwnershipService
{
    public function ensureBelongsToSeries(Series $series, Photo $photo): void
    {
        if ($photo->series_id !== $series->id) {
            abort(404);
        }
    }
}
