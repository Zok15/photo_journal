<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListSeriesPhotosRequest;
use App\Http\Requests\StoreSeriesPhotosRequest;
use App\Http\Requests\SyncPhotoTagsRequest;
use App\Http\Requests\UpdateSeriesPhotoRequest;
use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use App\Services\PhotoBatchUploader;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class SeriesPhotoController extends Controller
{
    public function __construct(private PhotoBatchUploader $photoBatchUploader) {}

    public function index(ListSeriesPhotosRequest $request, Series $series): JsonResponse
    {
        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 15;
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $photos = $series->photos()
            ->with('tags')
            ->orderBy($sortBy, $sortDir)
            ->when($sortBy !== 'id', function ($query) use ($sortDir) {
                $query->orderBy('id', $sortDir);
            })
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($photos);
    }

    public function store(StoreSeriesPhotosRequest $request, Series $series): JsonResponse
    {
        $disk = config('filesystems.default');
        $files = $request->file('photos', []);
        $uploadResult = $this->photoBatchUploader->uploadToSeries($series, $files, $disk);
        $created = $uploadResult['created'];
        $failed = $uploadResult['failed'];

        if (count($created) === 0) {
            return response()->json([
                'message' => 'No photos were saved.',
                'photos_failed' => $failed,
            ], 422);
        }

        return response()->json([
            'photos_created' => $created,
            'photos_failed' => $failed,
        ], 201);
    }

    public function show(Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);

        $photo->load('tags');

        return response()->json([
            'data' => $photo,
        ]);
    }

    public function update(UpdateSeriesPhotoRequest $request, Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);

        $photo->update($request->validated());

        return response()->json([
            'data' => $photo->fresh()->load('tags'),
        ]);
    }

    public function destroy(Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);

        $disk = config('filesystems.default');
        Storage::disk($disk)->delete($photo->path);

        $photo->delete();

        return response()->json(status: 204);
    }

    public function syncTags(SyncPhotoTagsRequest $request, Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);

        $names = $this->normalizeTagNames($request->validated()['tags']);

        $tags = collect($names)->map(function (string $name) {
            return $this->findOrCreateTagSafely($name);
        });

        $photo->tags()->sync($tags->pluck('id')->all());
        $photo->load('tags');

        return response()->json([
            'data' => $photo,
        ]);
    }

    public function attachTags(SyncPhotoTagsRequest $request, Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);

        $names = $this->normalizeTagNames($request->validated()['tags']);

        $tags = collect($names)->map(function (string $name) {
            return $this->findOrCreateTagSafely($name);
        });

        $photo->tags()->syncWithoutDetaching($tags->pluck('id')->all());
        $photo->load('tags');

        return response()->json([
            'data' => $photo,
        ]);
    }

    public function detachTag(Series $series, Photo $photo, Tag $tag): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);

        $photo->tags()->detach($tag->id);
        $photo->load('tags');

        return response()->json([
            'data' => $photo,
        ]);
    }

    private function ensureSeriesPhoto(Series $series, Photo $photo): void
    {
        if ($photo->series_id !== $series->id) {
            abort(404);
        }
    }

    private function normalizeTagNames(array $tags): array
    {
        $normalize = function (string $name): string {
            $trimmed = trim($name);

            if (function_exists('mb_strtolower')) {
                return mb_strtolower($trimmed);
            }

            return strtolower($trimmed);
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
            return Tag::firstOrCreate(['name' => $name]);
        } catch (QueryException $e) {
            // Concurrent insert can violate unique(name). In that case
            // read and return the row created by the other request.
            if ($this->isUniqueViolation($e)) {
                $tag = Tag::query()->where('name', $name)->first();

                if ($tag !== null) {
                    return $tag;
                }
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23000';
    }
}
