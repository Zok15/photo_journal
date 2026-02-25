<?php

namespace App\Actions\SeriesRead;

use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Services\Series\SeriesHttpCacheService;
use App\Services\Series\SeriesPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ListPublicSeriesAction
{
    public function __construct(
        private SeriesPreviewService $seriesPreviewService,
        private SeriesHttpCacheService $seriesHttpCacheService
    ) {
    }

    /**
     * @param array<string, mixed> $validated
     */
    public function execute(Request $request, array $validated): JsonResponse
    {
        $perPage = $validated['per_page'] ?? 15;
        $sort = (string) ($validated['sort'] ?? 'new');
        $dateField = (string) ($validated['date_field'] ?? 'added');
        $seriesTable = (new Series())->getTable();

        $query = Series::query()
            ->where('is_public', true)
            ->where('publication_status', Series::PUBLICATION_PUBLISHED)
            ->with(['tags', 'user:id,name'])
            ->withCount('photos');

        $calendarDatesQuery = Series::query()
            ->where('is_public', true)
            ->where('publication_status', Series::PUBLICATION_PUBLISHED);

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

        $authorId = isset($validated['author_id']) ? (int) $validated['author_id'] : null;
        if ($authorId !== null) {
            $query->where('user_id', $authorId);
            $calendarDatesQuery->where('user_id', $authorId);
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
        $previewMap = $this->seriesPreviewService->buildSeriesPreviewMap($collection);

        $paginator->setCollection($collection->map(function (Series $series) use ($previewMap): array {
            $data = $series->toArray();
            $data['preview_photos'] = $previewMap[(int) $series->id] ?? [];
            $data['owner_name'] = (string) ($series->user?->name ?? '');

            return $data;
        }));

        $payload = $paginator->toArray();
        $payload['calendar_dates'] = $calendarDates;
        $payload['authors'] = User::query()
            ->select('users.id', 'users.name')
            ->join('series', 'series.user_id', '=', 'users.id')
            ->where('series.is_public', true)
            ->where('series.publication_status', Series::PUBLICATION_PUBLISHED)
            ->whereNotNull('users.name')
            ->distinct()
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
            ])
            ->values()
            ->all();
        $payload['author_suggestions'] = $this->buildPublicAuthorSuggestions();
        $payload['available_tags'] = Tag::query()
            ->whereHas('series', function ($builder): void {
                $builder
                    ->where('series.is_public', true)
                    ->where('series.publication_status', Series::PUBLICATION_PUBLISHED);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'name' => (string) $tag->name,
            ])
            ->values()
            ->all();

        $lastModified = $this->seriesHttpCacheService->latestTimestamp(
            (clone $query)->max($seriesTable.'.updated_at'),
            DB::table('photos')
                ->join($seriesTable, $seriesTable.'.id', '=', 'photos.series_id')
                ->where($seriesTable.'.is_public', true)
                ->where($seriesTable.'.publication_status', Series::PUBLICATION_PUBLISHED)
                ->max('photos.updated_at'),
            DB::table('series_tag')
                ->join($seriesTable, $seriesTable.'.id', '=', 'series_tag.series_id')
                ->join('tags', 'tags.id', '=', 'series_tag.tag_id')
                ->where($seriesTable.'.is_public', true)
                ->where($seriesTable.'.publication_status', Series::PUBLICATION_PUBLISHED)
                ->max('tags.updated_at'),
        );

        return $this->seriesHttpCacheService->respondWithConditionalJson($request, $payload, $lastModified);
    }

    /**
     * @return array<int, array{id:int,name:string,series_count:int,period_days:int}>
     */
    private function buildPublicAuthorSuggestions(): array
    {
        foreach ([3, 7, 30, 365] as $periodDays) {
            $cutoff = Carbon::now()->subDays($periodDays);

            $authors = User::query()
                ->select('users.id', 'users.name')
                ->selectRaw('COUNT(series.id) as series_count')
                ->join('series', 'series.user_id', '=', 'users.id')
                ->where('series.is_public', true)
                ->where('series.publication_status', Series::PUBLICATION_PUBLISHED)
                ->where('series.created_at', '>=', $cutoff)
                ->whereNotNull('users.name')
                ->where('users.name', '<>', '')
                ->groupBy('users.id', 'users.name')
                ->orderByDesc('series_count')
                ->orderBy('users.name')
                ->limit(5)
                ->get();

            if ($authors->isEmpty()) {
                continue;
            }

            return $authors
                ->map(fn (User $user): array => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'series_count' => (int) ($user->series_count ?? 0),
                    'period_days' => $periodDays,
                ])
                ->values()
                ->all();
        }

        return [];
    }
}
