<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeriesWithPhotosRequest;
use App\Jobs\ProcessSeries;
use App\Models\Series;
use App\Models\Tag;
use App\Services\PhotoBatchUploader;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
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
            }
        }

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

        $cacheKey = $this->buildSeriesIndexCacheKey($request, $validated, $perPage);
        $cacheTtl = now()->addSeconds($this->responseCacheTtlSeconds());
        $payload = Cache::remember($cacheKey, $cacheTtl, static fn () => $query->paginate($perPage)->withQueryString()->toArray());

        return $this->respondWithConditionalJson($request, $payload);
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

        if ($request->boolean('include_photos')) {
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

        $cacheKey = $this->buildSeriesShowCacheKey($request, $series, $validated);
        $cacheTtl = now()->addSeconds($this->responseCacheTtlSeconds());
        $payload = Cache::remember($cacheKey, $cacheTtl, static fn () => $payload);

        return $this->respondWithConditionalJson($request, $payload);
    }

    public function update(Request $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $series->update($data);

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

    private function buildSeriesIndexCacheKey(Request $request, array $validated, int $perPage): string
    {
        $normalized = $validated;
        $normalized['per_page'] = $perPage;
        ksort($normalized);

        return 'series:index:user:'.$request->user()->id.':'.sha1(json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function buildSeriesShowCacheKey(Request $request, Series $series, array $validated): string
    {
        $normalized = [
            'include_photos' => (bool) ($validated['include_photos'] ?? false),
            'photos_limit' => (int) ($validated['photos_limit'] ?? 30),
            'series_updated_at' => optional($series->updated_at)?->toAtomString(),
        ];
        ksort($normalized);

        return 'series:show:user:'.$request->user()->id.':series:'.$series->id.':'.sha1(json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function respondWithConditionalJson(Request $request, array $payload): JsonResponse
    {
        $etagHash = sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($this->ifNoneMatchMatches($request, $etagHash)) {
            return response()
                ->json([], 304)
                ->setEtag($etagHash)
                ->header('Cache-Control', $this->cacheControlHeaderValue())
                ->header('Vary', 'Authorization, Accept');
        }

        return response()
            ->json($payload)
            ->setEtag($etagHash)
            ->header('Cache-Control', $this->cacheControlHeaderValue())
            ->header('Vary', 'Authorization, Accept');
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
        $ttl = $this->responseCacheTtlSeconds();
        $swr = $ttl * 2;

        return "private, max-age={$ttl}, stale-while-revalidate={$swr}";
    }
}
