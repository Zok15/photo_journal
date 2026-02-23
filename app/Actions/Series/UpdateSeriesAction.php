<?php

namespace App\Actions\Series;

use App\Jobs\ModerateSeriesContent;
use App\Models\Series;
use App\Services\Series\SeriesCacheService;

class UpdateSeriesAction
{
    public function __construct(private SeriesCacheService $seriesCacheService)
    {
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{data:Series}
     */
    public function execute(Series $series, array $validated): array
    {
        $requestedPublic = array_key_exists('is_public', $validated) ? (bool) $validated['is_public'] : null;
        unset($validated['is_public']);

        $series->fill($validated);

        if ($requestedPublic === true && ! $series->isPublished()) {
            $series->forceFill([
                'is_public' => false,
                'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
                'moderation_status' => Series::MODERATION_PENDING,
                'publication_requested_at' => now(),
                'moderation_reason' => null,
                'moderation_labels' => [],
                'moderated_at' => null,
                'moderated_by' => null,
            ]);
        }

        if ($requestedPublic === false) {
            $series->forceFill([
                'is_public' => false,
                'publication_status' => Series::PUBLICATION_DRAFT,
                'publication_requested_at' => null,
            ]);
        }

        $series->save();

        if ($requestedPublic === true && (string) $series->publication_status === Series::PUBLICATION_PENDING_MODERATION) {
            ModerateSeriesContent::dispatch($series->id);
        }

        $this->seriesCacheService->invalidateForSeries($series);

        return [
            'data' => $series->fresh()->loadCount('photos')->load('tags'),
        ];
    }
}
