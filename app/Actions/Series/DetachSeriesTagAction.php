<?php

namespace App\Actions\Series;

use App\Models\Series;
use App\Models\Tag;
use App\Services\Series\SeriesCacheService;
use App\Services\Series\SeriesTagMutationService;

class DetachSeriesTagAction
{
    public function __construct(
        private SeriesTagMutationService $seriesTagMutationService,
        private SeriesCacheService $seriesCacheService
    ) {
    }

    /**
     * @return array{data:Series}
     */
    public function execute(Series $series, Tag $tag): array
    {
        $detached = $this->seriesTagMutationService->detachTag($series, $tag);
        if ($detached > 0) {
            $this->seriesCacheService->touchForConditionalCache($series);
        }

        $this->seriesTagMutationService->cleanupUnusedTag($tag);
        $this->seriesCacheService->invalidateForSeries($series);

        return [
            'data' => $series->fresh()->loadCount('photos')->load('tags'),
        ];
    }
}
