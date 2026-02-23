<?php

namespace App\Actions\SeriesRead;

use App\Models\Series;
use App\Models\Tag;
use App\Services\Series\SeriesHttpCacheService;
use App\Services\Series\SeriesPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListSeriesAction
{
    public function __construct(
        private SeriesPreviewService $seriesPreviewService,
        private SeriesHttpCacheService $seriesHttpCacheService
    ) {
    }

    /**
     * @param array<string, mixed> $validated
     */
    public function execute(Request $request, array $validated, int $userId): JsonResponse
    {
        $perPage = $validated['per_page'] ?? 15;

        $query = Series::query()
            ->where('user_id', $userId)
            ->with('tags')
            ->withCount('photos');

        $calendarDatesQuery = Series::query()->where('user_id', $userId);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
            $calendarDatesQuery->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $tagFilter = trim((string) ($validated['tag'] ?? ''));
        if ($tagFilter !== '') {
            $tags = collect(explode(',', $tagFilter))
                ->map(fn ($tag): string => Tag::normalizeTagName((string) $tag))
                ->filter()
                ->unique()
                ->values()
                ->all();

            foreach ($tags as $tagName) {
                $query->whereHas('tags', function ($builder) use ($tagName): void {
                    $builder->where('name', $tagName);
                });
                $calendarDatesQuery->whereHas('tags', function ($builder) use ($tagName): void {
                    $builder->where('name', $tagName);
                });
            }
        }

        $calendarDates = $calendarDatesQuery
            ->selectRaw('DATE(created_at) as date_key')
            ->distinct()
            ->orderBy('date_key')
            ->pluck('date_key')
            ->filter()
            ->values()
            ->all();

        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;
        if ($dateFrom !== null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if (($validated['sort'] ?? null) === 'old') {
            $query->oldest();
        } else {
            $query->latest();
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $collection = $paginator->getCollection();
        $previewMap = $this->seriesPreviewService->buildSeriesPreviewMap($collection);

        $paginator->setCollection($collection->map(function (Series $series) use ($previewMap): array {
            $data = $series->toArray();
            $data['preview_photos'] = $previewMap[(int) $series->id] ?? [];

            return $data;
        }));

        $payload = $paginator->toArray();
        $payload['calendar_dates'] = $calendarDates;

        $seriesTable = (new Series())->getTable();
        $lastModified = $this->seriesHttpCacheService->latestTimestamp(
            (clone $query)->max($seriesTable.'.updated_at'),
            DB::table('photos')
                ->join($seriesTable, $seriesTable.'.id', '=', 'photos.series_id')
                ->where($seriesTable.'.user_id', $userId)
                ->max('photos.updated_at'),
            DB::table('series_tag')
                ->join($seriesTable, $seriesTable.'.id', '=', 'series_tag.series_id')
                ->join('tags', 'tags.id', '=', 'series_tag.tag_id')
                ->where($seriesTable.'.user_id', $userId)
                ->max('tags.updated_at'),
        );

        return $this->seriesHttpCacheService->respondWithConditionalJson($request, $payload, $lastModified);
    }
}
