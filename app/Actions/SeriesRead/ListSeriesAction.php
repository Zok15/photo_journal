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
        $statusOnly = (bool) ($validated['status_only'] ?? false);
        $includeBlockingTags = (bool) ($validated['include_blocking_tags'] ?? false);
        $perPage = $validated['per_page'] ?? 15;
        $sort = (string) ($validated['sort'] ?? 'new');
        $dateField = (string) ($validated['date_field'] ?? 'added');
        $seriesTable = (new Series())->getTable();

        $query = Series::query()->where('user_id', $userId);
        if (! $statusOnly) {
            $query->with('tags')->withCount('photos');
        }

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

        $calendarDates = [];
        if (! $statusOnly) {
            if ($dateField === 'taken') {
                $calendarDates = $calendarDatesQuery
                    ->join('photos', 'photos.series_id', '=', $seriesTable.'.id')
                    ->join('photo_metadata', 'photo_metadata.photo_id', '=', 'photos.id')
                    ->whereNotNull('photo_metadata.taken_at')
                    ->selectRaw('DATE(photo_metadata.taken_at) as date_key')
                    ->distinct()
                    ->orderBy('date_key')
                    ->pluck('date_key')
                    ->filter()
                    ->values()
                    ->all();
            } else {
                $calendarDates = $calendarDatesQuery
                    ->selectRaw('DATE('.$seriesTable.'.created_at) as date_key')
                    ->distinct()
                    ->orderBy('date_key')
                    ->pluck('date_key')
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;
        if ($dateField === 'taken') {
            if ($dateFrom !== null || $dateTo !== null) {
                $query->whereExists(function ($builder) use ($dateFrom, $dateTo, $seriesTable): void {
                    $builder->selectRaw('1')
                        ->from('photos')
                        ->join('photo_metadata', 'photo_metadata.photo_id', '=', 'photos.id')
                        ->whereColumn('photos.series_id', $seriesTable.'.id')
                        ->whereNotNull('photo_metadata.taken_at');

                    if ($dateFrom !== null) {
                        $builder->whereDate('photo_metadata.taken_at', '>=', $dateFrom);
                    }
                    if ($dateTo !== null) {
                        $builder->whereDate('photo_metadata.taken_at', '<=', $dateTo);
                    }
                });
            }
        } else {
            if ($dateFrom !== null) {
                $query->whereDate($seriesTable.'.created_at', '>=', $dateFrom);
            }
            if ($dateTo !== null) {
                $query->whereDate($seriesTable.'.created_at', '<=', $dateTo);
            }
        }

        if (in_array($sort, ['taken_new', 'taken_old'], true)) {
            $photoTakenSubquery = DB::table('photos')
                ->leftJoin('photo_metadata', 'photo_metadata.photo_id', '=', 'photos.id')
                ->selectRaw('photos.series_id, MAX(photo_metadata.taken_at) as latest_taken_at')
                ->groupBy('photos.series_id');

            $query
                ->leftJoinSub($photoTakenSubquery, 'photo_taken_sort', function ($join) use ($seriesTable): void {
                    $join->on('photo_taken_sort.series_id', '=', $seriesTable.'.id');
                })
                ->select($seriesTable.'.*');

            if ($sort === 'taken_old') {
                $query
                    ->orderByRaw('COALESCE(photo_taken_sort.latest_taken_at, '.$seriesTable.'.created_at) ASC')
                    ->orderBy($seriesTable.'.id');
            } else {
                $query
                    ->orderByRaw('COALESCE(photo_taken_sort.latest_taken_at, '.$seriesTable.'.created_at) DESC')
                    ->orderByDesc($seriesTable.'.id');
            }
        } elseif ($sort === 'old') {
            $query->orderBy($seriesTable.'.created_at');
        } else {
            $query->orderByDesc($seriesTable.'.created_at');
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $collection = $paginator->getCollection();
        if ($statusOnly) {
            $paginator->setCollection($collection->map(
                function (Series $series) use ($includeBlockingTags): array {
                    $data = [
                        'id' => (int) $series->id,
                        'publication_status' => (string) $series->publication_status,
                        'moderation_status' => (string) $series->moderation_status,
                        'is_public' => (bool) $series->is_public,
                    ];
                    if ($includeBlockingTags) {
                        $data['moderation_labels'] = array_values(array_filter(
                            (array) ($series->moderation_labels ?? []),
                            static fn ($value): bool => is_string($value) && trim($value) !== ''
                        ));
                    }

                    return $data;
                }
            ));
        } else {
            $previewMap = $this->seriesPreviewService->buildSeriesPreviewMap($collection);
            $paginator->setCollection($collection->map(function (Series $series) use ($previewMap): array {
                $data = $series->toArray();
                $data['preview_photos'] = $previewMap[(int) $series->id] ?? [];

                return $data;
            }));
        }

        $payload = $paginator->toArray();
        if (! $statusOnly) {
            $payload['calendar_dates'] = $calendarDates;
        }

        if ($statusOnly) {
            return response()
                ->json($payload)
                ->header('Cache-Control', 'private, no-store')
                ->header('Vary', 'Authorization, Accept');
        }

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
