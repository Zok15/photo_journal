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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SeriesPhotoController extends Controller
{
    public function index(ListSeriesPhotosRequest $request, Series $series): JsonResponse
    {
        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 15;
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $photos = $series->photos()
            ->with('tags')
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($photos);
    }

    public function store(StoreSeriesPhotosRequest $request, Series $series): JsonResponse
    {
        $disk = config('filesystems.default');
        $directory = "photos/series/{$series->id}";
        $files = $request->file('photos', []);

        $created = [];

        /** @var UploadedFile $file */
        foreach ($files as $file) {
            $path = $this->storeOrFail($file, $directory, $disk);

            $created[] = $series->photos()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ]);
        }

        return response()->json([
            'data' => $created,
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
            return Tag::firstOrCreate(['name' => $name]);
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
            return Tag::firstOrCreate(['name' => $name]);
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
        return collect($tags)
            ->map(fn (string $name) => strtolower(trim($name)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function storeOrFail(UploadedFile $file, string $directoryPath, string $disk): string
    {
        $path = $file->store($directoryPath, $disk);

        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Failed to store uploaded file.');
        }

        return $path;
    }
}
