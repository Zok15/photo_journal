<?php

namespace App\Actions\SeriesPhoto;

use App\Models\Series;
use App\Services\Series\SeriesCacheService;
use Illuminate\Support\Facades\DB;

class ReorderSeriesPhotosAction
{
    public function __construct(private SeriesCacheService $seriesCacheService)
    {
    }

    /**
     * @param array<int, int> $photoIds
     * @return array{status:int, payload:array<string,mixed>}
     */
    public function execute(Series $series, array $photoIds): array
    {
        $normalizedInput = $photoIds;
        sort($normalizedInput);

        $seriesPhotoIds = $series->photos()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        sort($seriesPhotoIds);

        if ($normalizedInput !== $seriesPhotoIds) {
            return [
                'status' => 422,
                'payload' => [
                    'message' => 'photo_ids must contain all photos of the series exactly once.',
                ],
            ];
        }

        DB::transaction(function () use ($series, $photoIds): void {
            foreach ($photoIds as $index => $photoId) {
                $series->photos()
                    ->whereKey($photoId)
                    ->update([
                        'sort_order' => $index + 1,
                    ]);
            }
        });

        $this->seriesCacheService->invalidateForSeries($series);

        return [
            'status' => 200,
            'payload' => [
                'data' => [
                    'photo_ids' => $photoIds,
                ],
            ],
        ];
    }
}
