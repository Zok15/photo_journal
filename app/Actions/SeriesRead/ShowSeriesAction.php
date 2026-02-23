<?php

namespace App\Actions\SeriesRead;

use App\Models\Series;
use App\Services\Series\SeriesHttpCacheService;
use App\Services\Series\SeriesPhotoUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowSeriesAction
{
    public function __construct(
        private SeriesPhotoUrlService $seriesPhotoUrlService,
        private SeriesHttpCacheService $seriesHttpCacheService
    ) {
    }

    /**
     * @param array<string, mixed> $validated
     */
    public function execute(Request $request, Series $series, array $validated): JsonResponse
    {
        $includePhotos = $request->boolean('include_photos');

        if ($includePhotos) {
            $limit = $validated['photos_limit'] ?? 30;
            $disk = (string) config('filesystems.default');

            $series->load([
                'photos' => fn ($query) => $query
                    ->orderByRaw('sort_order IS NULL')
                    ->orderBy('sort_order')
                    ->latest()
                    ->limit($limit),
            ]);

            $series->photos->each(function ($photo) use ($disk): void {
                $photo->setAttribute('preview_url', $this->seriesPhotoUrlService->resolvePreviewUrl($disk, $photo->path));
                $photo->setAttribute('public_url', $this->seriesPhotoUrlService->resolvePublicUrl($disk, $photo->path));
            });
        }

        $series->loadCount('photos')->load('tags');

        $payload = [
            'data' => $series->toArray(),
        ];

        if (! $includePhotos) {
            $cacheKey = $this->seriesHttpCacheService->buildSeriesShowCacheKey(
                (int) $request->user()->id,
                (int) $series->id,
                $validated
            );
            $payload = $this->seriesHttpCacheService->cachedPayload($cacheKey, static fn (): array => $payload, 'series.show');
        }

        $lastModified = $this->seriesHttpCacheService->latestTimestamp(
            $series->updated_at,
            $series->photos()->max('updated_at'),
            $series->tags()->max('tags.updated_at'),
        );

        if ($includePhotos) {
            return response()
                ->json($payload)
                ->header('Cache-Control', 'private, no-store')
                ->header('Vary', 'Authorization, Accept');
        }

        return $this->seriesHttpCacheService->respondWithConditionalJson($request, $payload, $lastModified);
    }
}
