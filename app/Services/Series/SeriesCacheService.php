<?php

namespace App\Services\Series;

use App\Models\Series;
use App\Support\SeriesResponseCache;

class SeriesCacheService
{
    public function invalidateForSeries(Series $series): void
    {
        SeriesResponseCache::bumpUser((int) $series->user_id);
        SeriesResponseCache::bumpSeries((int) $series->id);
    }

    public function invalidate(int $userId, int $seriesId): void
    {
        SeriesResponseCache::bumpUser($userId);
        SeriesResponseCache::bumpSeries($seriesId);
    }

    public function touchForConditionalCache(Series $series): void
    {
        // If-Modified-Since is second-precision; bump timestamp to avoid false 304.
        $series->forceFill([
            'updated_at' => now()->addSecond(),
        ])->saveQuietly();
    }
}
