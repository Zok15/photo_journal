<?php

namespace App\Http\Controllers\Api;

use App\Actions\Series\AttachSeriesTagsAction;
use App\Actions\Series\DestroySeriesAction;
use App\Actions\Series\DetachSeriesTagAction;
use App\Actions\Series\StoreSeriesAction;
use App\Actions\Series\UpdateSeriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeriesWithPhotosRequest;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Services\Series\SeriesPhotoUrlService;
use App\Support\SeriesResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Основной API-контроллер фотосерий.
 *
 * Здесь собраны:
 * - CRUD по сериям;
 * - привязка/отвязка тегов;
 * - выдача превью с учетом кеширования и условных запросов (ETag/Last-Modified).
 */
class SeriesController extends Controller
{
    public function __construct(
        private SeriesPhotoUrlService $seriesPhotoUrlService,
        private StoreSeriesAction $storeSeriesAction,
        private UpdateSeriesAction $updateSeriesAction,
        private DestroySeriesAction $destroySeriesAction,
        private AttachSeriesTagsAction $attachSeriesTagsAction,
        private DetachSeriesTagAction $detachSeriesTagAction
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Series::class);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:new,old'],
        ]);

        $perPage = $validated['per_page'] ?? 15;
        // $query — основной список серий для пагинации.
        $query = Series::query()
            ->where('user_id', $request->user()->id)
            ->with('tags')
            ->withCount('photos');
        // Отдельный запрос для календарных маркеров дат.
        $calendarDatesQuery = Series::query()
            ->where('user_id', $request->user()->id);

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

        $sort = $validated['sort'] ?? null;
        if ($sort === 'old') {
            $query->oldest();
        } else {
            // Keep existing behavior when no sort is provided.
            $query->latest();
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $collection = $paginator->getCollection();
        // Превью подгружаем батчем, чтобы не делать N+1 запросов.
        $previewMap = $this->buildSeriesPreviewMap($collection);

        $paginator->setCollection($collection->map(function (Series $series) use ($previewMap): array {
            $data = $series->toArray();
            $data['preview_photos'] = $previewMap[(int) $series->id] ?? [];

            return $data;
        }));

        $payload = $paginator->toArray();
        $payload['calendar_dates'] = $calendarDates;

        $userId = (int) $request->user()->id;
        $seriesTable = (new Series())->getTable();

        $lastModified = $this->latestTimestamp(
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

        // Возвращаем ответ с ETag/Last-Modified для условного кеширования на клиенте.
        return $this->respondWithConditionalJson($request, $payload, $lastModified);
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:255'],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:new,old'],
        ]);

        $perPage = $validated['per_page'] ?? 15;
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

        $authorId = isset($validated['author_id']) ? (int) $validated['author_id'] : null;
        if ($authorId !== null) {
            $query->where('user_id', $authorId);
            $calendarDatesQuery->where('user_id', $authorId);
        }

        $sort = $validated['sort'] ?? null;
        if ($sort === 'old') {
            $query->oldest();
        } else {
            $query->latest();
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $collection = $paginator->getCollection();
        $previewMap = $this->buildSeriesPreviewMap($collection);

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

        $seriesTable = (new Series())->getTable();
        $lastModified = $this->latestTimestamp(
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

        return $this->respondWithConditionalJson($request, $payload, $lastModified);
    }

    public function publicShow(Request $request, Series $series): JsonResponse
    {
        if (! (bool) $series->is_public || (string) $series->publication_status !== Series::PUBLICATION_PUBLISHED) {
            abort(404);
        }

        $validated = $request->validate([
            'include_photos' => ['nullable', 'boolean'],
            'photos_limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);
        $includePhotos = $request->boolean('include_photos', true);

        if ($includePhotos) {
            $limit = $validated['photos_limit'] ?? 120;
            $disk = config('filesystems.default');

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

        $series->loadCount('photos')->load(['tags', 'user:id,name']);
        $data = $series->toArray();
        $data['owner_name'] = (string) ($series->user?->name ?? '');

        $payload = [
            'data' => $data,
        ];

        $lastModified = $this->latestTimestamp(
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

        return $this->respondWithConditionalJson($request, $payload, $lastModified)
            ->header('Vary', 'Accept');
    }

    public function store(StoreSeriesWithPhotosRequest $request): JsonResponse
    {
        $this->authorize('create', Series::class);

        $result = $this->storeSeriesAction->execute(
            (int) $request->user()->id,
            $request->validated(),
            $request->file('photos', []),
            (string) config('filesystems.default')
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function show(Request $request, Series $series): JsonResponse
    {
        $this->authorize('view', $series);

        $validated = $request->validate([
            'include_photos' => ['nullable', 'boolean'],
            'photos_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $includePhotos = $request->boolean('include_photos');

        if ($includePhotos) {
            $limit = $validated['photos_limit'] ?? 30;
            $disk = config('filesystems.default');

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

        // Responses with photo previews contain temporary signed URLs.
        // They must never be cached/304-revalidated, otherwise clients keep expired links.
        if (! $includePhotos) {
            $cacheKey = $this->buildSeriesShowCacheKey($request, $series, $validated);
            $payload = $this->cachedPayload($cacheKey, static fn () => $payload, 'series.show');
        }

        $lastModified = $this->latestTimestamp(
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

        return $this->respondWithConditionalJson($request, $payload, $lastModified);
    }

    public function update(Request $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $result = $this->updateSeriesAction->execute($series, $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]));

        return response()->json([
            'data' => $result['data'],
        ]);
    }

    public function destroy(Series $series): JsonResponse
    {
        $this->authorize('delete', $series);

        $this->destroySeriesAction->execute($series, (string) config('filesystems.default'));

        return response()->json(status: 204);
    }

    public function attachTags(Request $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $data = $request->validate([
            'tags' => ['required', 'array', 'min:1', 'max:50'],
            'tags.*' => ['required', 'string', 'max:120'],
        ]);

        $result = $this->attachSeriesTagsAction->execute($series, $data['tags']);

        return response()->json($result['payload'], $result['status']);
    }

    public function detachTag(Series $series, Tag $tag): JsonResponse
    {
        $this->authorize('update', $series);

        $result = $this->detachSeriesTagAction->execute($series, $tag);

        return response()->json([
            'data' => $result['data'],
        ]);
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

    /**
     * @param \Illuminate\Support\Collection<int, Series> $seriesCollection
     * @return array<int, array<int, array{id:int, path:string|null, original_name:string|null, preview_url:string|null, public_url:string|null}>>
     */
    private function buildSeriesPreviewMap(\Illuminate\Support\Collection $seriesCollection): array
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
        $disk = config('filesystems.default');

        // Берем не все фото серии, а только первые N по отображаемому порядку.
        // Используем оконную функцию, чтобы ограничить выборку по каждой серии одним SQL-запросом.
        $rankedPhotos = DB::table('photos')
            ->select(['id', 'series_id', 'path', 'original_name'])
            ->selectRaw(
                'ROW_NUMBER() OVER (
                    PARTITION BY series_id
                    ORDER BY sort_order IS NULL, sort_order, created_at DESC, id DESC
                ) AS row_num'
            )
            ->whereIn('series_id', $seriesIds);

        /** @var \Illuminate\Support\Collection<int, object{id:int|string,series_id:int|string,path:?string,original_name:?string,row_num:int|string}> $photos */
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
                'original_name' => $photo->original_name,
                'preview_url' => $this->seriesPhotoUrlService->resolvePreviewUrl($disk, $photo->path),
                'public_url' => $this->seriesPhotoUrlService->resolvePublicUrl($disk, $photo->path),
            ];
        }

        return $map;
    }

    private function responseCacheTtlSeconds(): int
    {
        return max(5, (int) config('app.series_response_cache_ttl_seconds', 20));
    }

    private function buildSeriesShowCacheKey(Request $request, Series $series, array $validated): string
    {
        $normalized = [
            'include_photos' => (bool) ($validated['include_photos'] ?? false),
            'photos_limit' => (int) ($validated['photos_limit'] ?? 30),
        ];

        return SeriesResponseCache::showKey((int) $request->user()->id, (int) $series->id, $normalized);
    }

    private function respondWithConditionalJson(Request $request, array $payload, string|Carbon|null $lastModified = null): JsonResponse
    {
        // ETag считаем от JSON-представления payload.
        $etagHash = sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $lastModifiedAt = $lastModified instanceof Carbon
            ? $lastModified
            : ($lastModified !== null ? Carbon::parse($lastModified) : null);

        if ($lastModifiedAt !== null && $this->ifModifiedSinceNotChanged($request, $lastModifiedAt)) {
            return response()
                ->json([], 304)
                ->setEtag($etagHash)
                ->setLastModified($lastModifiedAt)
                ->header('Cache-Control', $this->cacheControlHeaderValue())
                ->header('Vary', 'Authorization, Accept');
        }

        if ($this->ifNoneMatchMatches($request, $etagHash)) {
            $response = response()
                ->json([], 304)
                ->setEtag($etagHash)
                ->header('Cache-Control', $this->cacheControlHeaderValue())
                ->header('Vary', 'Authorization, Accept');

            if ($lastModifiedAt !== null) {
                $response->setLastModified($lastModifiedAt);
            }

            return $response;
        }

        $response = response()
            ->json($payload)
            ->setEtag($etagHash)
            ->header('Cache-Control', $this->cacheControlHeaderValue())
            ->header('Vary', 'Authorization, Accept');

        if ($lastModifiedAt !== null) {
            $response->setLastModified($lastModifiedAt);
        }

        return $response;
    }

    private function ifNoneMatchMatches(Request $request, string $etagHash): bool
    {
        $header = trim((string) $request->header('If-None-Match', ''));
        if ($header === '') {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        $quotedEtag = '"'.$etagHash.'"';
        $weakQuotedEtag = 'W/'.$quotedEtag;

        return collect(explode(',', $header))
            ->map(static fn (string $value): string => trim($value))
            ->contains(static fn (string $value): bool => in_array($value, [$quotedEtag, $weakQuotedEtag], true));
    }

    private function cacheControlHeaderValue(): string
    {
        return 'private, no-cache, max-age=0, must-revalidate';
    }

    private function ifModifiedSinceNotChanged(Request $request, Carbon $lastModified): bool
    {
        $value = trim((string) $request->header('If-Modified-Since', ''));
        if ($value === '') {
            return false;
        }

        try {
            $since = Carbon::parse($value);
        } catch (\Throwable) {
            return false;
        }

        return $lastModified->lessThanOrEqualTo($since);
    }

    private function latestTimestamp(string|Carbon|null ...$candidates): ?Carbon
    {
        $result = null;

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            try {
                $timestamp = $candidate instanceof Carbon ? $candidate : Carbon::parse((string) $candidate);
            } catch (\Throwable) {
                continue;
            }

            if ($result === null || $timestamp->gt($result)) {
                $result = $timestamp;
            }
        }

        return $result;
    }

    private function cachedPayload(string $cacheKey, \Closure $resolver, string $scope): array
    {
        $startedAt = microtime(true);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            // Hit: вернули из кеша без пересборки payload.
            $this->logCacheMetric($scope, 'hit', $startedAt);

            return $cached;
        }

        $payload = $resolver();
        // Miss: строим заново и кладем в кеш на короткое время.
        Cache::put($cacheKey, $payload, now()->addSeconds($this->responseCacheTtlSeconds()));
        $this->logCacheMetric($scope, 'miss', $startedAt);

        return $payload;
    }

    private function logCacheMetric(string $scope, string $result, float $startedAt): void
    {
        Log::info('series.response_cache', [
            'scope' => $scope,
            'result' => $result,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

}
