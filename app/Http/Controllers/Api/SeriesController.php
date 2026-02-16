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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeriesController extends Controller
{
    public function __construct(private PhotoBatchUploader $photoBatchUploader) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Series::class);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = $validated['per_page'] ?? 15;

        $series = Series::query()
            ->where('user_id', $request->user()->id)
            ->with('tags')
            ->withCount('photos')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($series);
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

        return response()->json([
            'data' => $series,
        ]);
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

        if (!empty($photoPaths)) {
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
        if (!$stillUsedInSeries && !$stillUsedInPhotos) {
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
        $normalize = function (string $name): string {
            $trimmed = trim($name);
            if ($trimmed === '') {
                return '';
            }

            $ascii = Str::ascii($trimmed);
            $words = preg_replace('/[^A-Za-z0-9]+/', ' ', $ascii) ?? '';
            $camel = Str::camel(trim($words));

            return $camel !== '' ? $camel : 'tag';
        };

        return collect($tags)
            ->map($normalize)
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

}
