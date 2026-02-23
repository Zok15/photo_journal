<?php

namespace App\Actions\Series;

use App\Models\Series;
use App\Services\Series\SeriesCacheService;
use App\Services\Series\SeriesTagMutationService;

class AttachSeriesTagsAction
{
    public function __construct(
        private SeriesTagMutationService $seriesTagMutationService,
        private SeriesCacheService $seriesCacheService
    ) {
    }

    /**
     * @param array<int, string> $tags
     * @return array{status:int, payload:array<string,mixed>}
     */
    public function execute(Series $series, array $tags): array
    {
        $normalizedNames = $this->seriesTagMutationService->normalizeTagNames($tags);
        if ($normalizedNames === []) {
            return [
                'status' => 422,
                'payload' => [
                    'message' => 'At least one valid tag is required.',
                ],
            ];
        }

        $result = $this->seriesTagMutationService->attachManualTags($series, $normalizedNames);
        if (($result['attached_count'] ?? 0) > 0 || ($result['forced_manual_count'] ?? 0) > 0) {
            $this->seriesCacheService->touchForConditionalCache($series);
        }
        $this->seriesCacheService->invalidateForSeries($series);

        return [
            'status' => 200,
            'payload' => [
                'data' => $series->fresh()->loadCount('photos')->load('tags'),
            ],
        ];
    }
}
