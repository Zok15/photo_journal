<?php

namespace App\Services\Series;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SeriesPreviewService
{
    public function __construct(private SeriesPhotoUrlService $seriesPhotoUrlService)
    {
    }

    /**
     * @param Collection<int, \App\Models\Series> $seriesCollection
     * @return array<int, array<int, array{id:int, path:string|null, original_name:string|null, preview_url:string|null, public_url:string|null}>>
     */
    public function buildSeriesPreviewMap(Collection $seriesCollection): array
    {
        $seriesIds = $seriesCollection
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($seriesIds === []) {
            return [];
        }

        $limit = max(1, (int) config('photo_processing.series_preview_photos_limit', 18));
        $disk = (string) config('filesystems.default');

        $rankedPhotos = DB::table('photos')
            ->select(['id', 'series_id', 'path', 'preview_path', 'original_name'])
            ->selectRaw(
                'ROW_NUMBER() OVER (
                    PARTITION BY series_id
                    ORDER BY sort_order IS NULL, sort_order, created_at DESC, id DESC
                ) AS row_num'
            )
            ->whereIn('series_id', $seriesIds);

        $photos = DB::query()
            ->fromSub($rankedPhotos, 'ranked_photos')
            ->where('row_num', '<=', $limit)
            ->orderBy('series_id')
            ->orderBy('row_num')
            ->get();

        $map = [];

        foreach ($photos as $photo) {
            $seriesId = (int) $photo->series_id;

            $map[$seriesId] ??= [];
            $map[$seriesId][] = [
                'id' => (int) $photo->id,
                'path' => $photo->path,
                'preview_path' => $photo->preview_path,
                'original_name' => $photo->original_name,
                'preview_url' => $this->seriesPhotoUrlService->resolvePreviewUrl(
                    $disk,
                    $photo->preview_path ?: $photo->path
                ),
                'public_url' => $this->seriesPhotoUrlService->resolvePublicUrl($disk, $photo->path),
            ];
        }

        return $map;
    }
}
