<?php

namespace App\Actions\SeriesRead;

use App\Models\Series;
use App\Services\Series\SeriesHttpCacheService;
use App\Services\Series\SeriesPhotoUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowPublicSeriesAction
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
        if (! (bool) $series->is_public || (string) $series->publication_status !== Series::PUBLICATION_PUBLISHED) {
            abort(404);
        }

        $includePhotos = $request->boolean('include_photos', true);

        if ($includePhotos) {
            $limit = $validated['photos_limit'] ?? 120;
            $disk = (string) config('filesystems.default');

            $series->load([
                'photos' => fn ($query) => $query
                    ->orderByRaw('sort_order IS NULL')
                    ->orderBy('sort_order')
                    ->latest()
                    ->limit($limit),
            ]);

            $series->photos->each(function ($photo) use ($disk): void {
                $photo->setAttribute('preview_url', $this->seriesPhotoUrlService->resolvePreviewUrl(
                    $disk,
                    $photo->preview_path ?: $photo->path
                ));
                $photo->setAttribute('public_url', $this->seriesPhotoUrlService->resolvePublicUrl($disk, $photo->path));
            });
        }

        $series->loadCount('photos')->load(['tags', 'user:id,name']);
        $data = $series->toArray();
        $data['owner_name'] = (string) ($series->user?->name ?? '');

        $payload = [
            'data' => $data,
        ];

        $lastModified = $this->seriesHttpCacheService->latestTimestamp(
            $series->updated_at,
            $series->photos()->max('updated_at'),
            $series->tags()->max('tags.updated_at'),
        );

        if ($includePhotos) {
            return response()
                ->json($payload)
                ->header('Cache-Control', 'public, no-store')
                ->header('Vary', 'Accept');
        }

        return $this->seriesHttpCacheService
            ->respondWithConditionalJson($request, $payload, $lastModified, 'private, no-cache, max-age=0, must-revalidate', 'Accept')
            ->header('Vary', 'Accept');
    }
}
