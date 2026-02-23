<?php

namespace App\Actions\SeriesPhotoRead;

use App\Models\Photo;
use App\Models\Series;
use App\Services\Series\SeriesCacheService;
use App\Services\Series\SeriesPhotoNameService;

class UpdateSeriesPhotoAction
{
    public function __construct(
        private SeriesPhotoNameService $seriesPhotoNameService,
        private SeriesCacheService $seriesCacheService
    ) {
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{data:Photo}
     */
    public function execute(Series $series, Photo $photo, array $validated): array
    {
        if (array_key_exists('original_name', $validated)) {
            $validated['original_name'] = $this->seriesPhotoNameService->normalizeOriginalName($photo, (string) $validated['original_name']);
        }

        $photo->update($validated);
        $this->seriesCacheService->invalidateForSeries($series);

        return [
            'data' => $photo->fresh(),
        ];
    }
}
