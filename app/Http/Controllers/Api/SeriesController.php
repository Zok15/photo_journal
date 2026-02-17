<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeriesWithPhotosRequest;
use App\Jobs\ProcessSeries;
use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use App\Services\PhotoBatchUploader;
use App\Support\SeriesResponseCache;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SeriesController extends Controller
{
    public function __construct(private PhotoBatchUploader $photoBatchUploader) {}

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
        $query = Series::query()
            ->where('user_id', $request->user()->id)
            ->with('tags')
            ->withCount('photos');
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
        $previewMap = $this->buildSeriesPreviewMap($collection);

        $paginator->setCollection($collection->map(function (Series $series) use ($previewMap): array {
            $data = $series->toArray();
            $data['preview_photos'] = $previewMap[(int) $series->id] ?? [];

            return $data;
        }));

        $payload = $paginator->toArray();
        $payload['calendar_dates'] = $calendarDates;

        $userId = (int) $request->user()->id;
        $seriesTable = (new Series)->getTable();

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

        return $this->respondWithConditionalJson($request, $payload, $lastModified);
    }

    public function store(StoreSeriesWithPhotosRequest $request): JsonResponse
    {
        $this->authorize('create', Series::class);

        $data = $request->validated();

        $disk = config('filesystems.default');
        $files = $request->file('photos', []);
        $series = Series::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
        ]);

        $uploadResult = $this->photoBatchUploader->uploadToSeries($series, $files, $disk);
        $created = $uploadResult['created'];
        $failed = $uploadResult['failed'];

        if (count($created) === 0) {
            $series->delete();

            return response()->json([
                'message' => 'No photos were saved.',
                'photos_failed' => $failed,
            ], 422);
        }

        ProcessSeries::dispatch($series->id);
        $this->invalidateSeriesCaches($request->user()->id, $series);

        return response()->json([
            'id' => $series->id,
            'status' => 'queued',
            'photos_created' => $created,
            'photos_failed' => $failed,
        ], 201);
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
                $photo->setAttribute('preview_url', $this->resolvePhotoPreviewUrl($disk, $photo->path));
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

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $series->update($data);
        $this->invalidateSeriesCaches($request->user()->id, $series);

        return response()->json([
            'data' => $series->fresh()->loadCount('photos')->load('tags'),
        ]);
    }

    public function destroy(Series $series): JsonResponse
    {
        $this->authorize('delete', $series);

        $disk = config('filesystems.default');
        $photoPaths = $series->photos()
            ->pluck('path')
            ->filter()
            ->values()
            ->all();

        $series->delete();

        if (! empty($photoPaths)) {
            Storage::disk($disk)->delete($photoPaths);
        }

        $this->invalidateSeriesCaches((int) $series->user_id, $series);

        return response()->json(status: 204);
    }

    public function attachTags(Request $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $data = $request->validate([
            'tags' => ['required', 'array', 'min:1', 'max:50'],
            'tags.*' => ['required', 'string', 'max:120'],
        ]);

        $names = $this->normalizeTagNames($data['tags']);
        $tags = collect($names)->map(fn (string $name): Tag => $this->findOrCreateTagSafely($name));

        $series->tags()->syncWithoutDetaching($tags->pluck('id')->all());
        $this->invalidateSeriesCaches((int) $series->user_id, $series);

        return response()->json([
            'data' => $series->fresh()->loadCount('photos')->load('tags'),
        ]);
    }

    public function detachTag(Series $series, Tag $tag): JsonResponse
    {
        $this->authorize('update', $series);

        $series->tags()->detach($tag->id);

        // Keep tag table compact: remove tags that are no longer referenced.
        $stillUsedInSeries = $tag->series()->exists();
        $stillUsedInPhotos = $tag->photos()->exists();
        if (! $stillUsedInSeries && ! $stillUsedInPhotos) {
            $tag->delete();
        }

        $this->invalidateSeriesCaches((int) $series->user_id, $series);

        return response()->json([
            'data' => $series->fresh()->loadCount('photos')->load('tags'),
        ]);
    }

    private function resolvePhotoPreviewUrl(string $disk, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $storage = Storage::disk($disk);

        try {
            return $storage->temporaryUrl($path, Carbon::now()->addMinutes(30));
        } catch (\Throwable) {
            return $storage->url($path);
        }
    }

    private function normalizeTagNames(array $tags): array
    {
        return collect($tags)
            ->map(fn (string $name): string => Tag::normalizeTagName($name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param \Illuminate\Support\Collection<int, Series> $seriesCollection
     * @return array<int, array<int, array{id:int, path:string|null, original_name:string|null, preview_url:string|null}>>
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

        $disk = config('filesystems.default');

        /** @var \Illuminate\Support\Collection<int, Photo> $photos */
        $photos = Photo::query()
            ->select(['id', 'series_id', 'path', 'original_name', 'sort_order', 'created_at'])
            ->whereIn('series_id', $seriesIds)
            ->orderBy('series_id')
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->latest('created_at')
            ->latest('id')
            ->get();

        $map = [];

        foreach ($photos as $photo) {
            $seriesId = (int) $photo->series_id;

            $map[$seriesId] ??= [];
            $map[$seriesId][] = [
                'id' => (int) $photo->id,
                'path' => $photo->path,
                'original_name' => $photo->original_name,
                'preview_url' => $this->resolvePhotoPreviewUrl($disk, $photo->path),
            ];
        }

        return $map;
    }

    private function findOrCreateTagSafely(string $name): Tag
    {
        try {
            $tag = Tag::firstOrCreate(['name' => $name]);

            // MySQL collations are often case-insensitive; normalize existing rows to canonical case.
            if ($tag->name !== $name) {
                $tag->name = $name;
                $tag->save();
                $tag->refresh();
            }

            return $tag;
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? null;

            if ($sqlState === '23000') {
                $existing = Tag::query()->where('name', $name)->first();
                if ($existing !== null) {
                    if ($existing->name !== $name) {
                        $existing->name = $name;
                        $existing->save();
                        $existing->refresh();
                    }

                    return $existing;
                }
            }

            throw $e;
        }
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
            $this->logCacheMetric($scope, 'hit', $startedAt);

            return $cached;
        }

        $payload = $resolver();
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

    private function invalidateSeriesCaches(int $userId, Series $series): void
    {
        SeriesResponseCache::bumpUser($userId);
        SeriesResponseCache::bumpSeries((int) $series->id);
    }
}
