<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListSeriesPhotosRequest;
use App\Http\Requests\StoreSeriesPhotosRequest;
use App\Http\Requests\UpdateSeriesPhotoRequest;
use App\Models\Photo;
use App\Models\Series;
use App\Services\PhotoAutoTagger;
use App\Services\PhotoBatchUploader;
use App\Support\SeriesResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeriesPhotoController extends Controller
{
    public function __construct(
        private PhotoBatchUploader $photoBatchUploader,
        private PhotoAutoTagger $photoAutoTagger
    ) {}

    public function index(ListSeriesPhotosRequest $request, Series $series): JsonResponse
    {
        $this->authorize('view', $series);

        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 15;
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $photos = $series->photos()
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
        $this->authorize('update', $series);

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

        $this->invalidateSeriesCaches($series);

        return response()->json([
            'photos_created' => $created,
            'photos_failed' => $failed,
        ], 201);
    }

    public function show(Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);
        $this->authorize('view', $photo);

        return response()->json([
            'data' => $photo,
        ]);
    }

    public function download(Series $series, Photo $photo): StreamedResponse
    {
        $this->ensureSeriesPhoto($series, $photo);
        $this->authorize('view', $photo);

        $disk = config('filesystems.default');
        $storage = Storage::disk($disk);

        abort_unless($storage->exists($photo->path), 404);

        $extension = strtolower(pathinfo((string) $photo->path, PATHINFO_EXTENSION));
        $fallback = 'photo-'.$photo->id.($extension !== '' ? '.'.$extension : '');
        $downloadName = trim((string) $photo->original_name) !== '' ? (string) $photo->original_name : $fallback;

        return $storage->download($photo->path, $downloadName);
    }

    public function reorder(Request $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $data = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1'],
            'photo_ids.*' => ['required', 'integer', 'distinct', 'exists:photos,id'],
        ]);

        $photoIds = array_map('intval', $data['photo_ids']);

        $seriesPhotoIds = $series->photos()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        sort($photoIds);
        $normalizedSeriesPhotoIds = $seriesPhotoIds;
        sort($normalizedSeriesPhotoIds);

        if ($photoIds !== $normalizedSeriesPhotoIds) {
            return response()->json([
                'message' => 'photo_ids must contain all photos of the series exactly once.',
            ], 422);
        }

        DB::transaction(function () use ($series, $data): void {
            foreach ($data['photo_ids'] as $index => $photoId) {
                $series->photos()
                    ->whereKey($photoId)
                    ->update([
                        'sort_order' => $index + 1,
                    ]);
            }
        });

        $this->invalidateSeriesCaches($series);

        return response()->json([
            'data' => [
                'photo_ids' => $data['photo_ids'],
            ],
        ]);
    }

    public function retag(Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        ['processed' => $processed, 'failed' => $failed] = $this->rebuildSeriesTagsFromPhotos($series);
        $this->invalidateSeriesCaches($series);

        return response()->json([
            'data' => [
                'processed' => $processed,
                'failed' => $failed,
                'tags_count' => $series->tags()->count(),
                'vision_enabled' => $this->photoAutoTagger->visionEnabled(),
                'vision_healthy' => $this->photoAutoTagger->visionHealthy(),
            ],
        ]);
    }

    public function update(UpdateSeriesPhotoRequest $request, Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);
        $this->authorize('update', $photo);

        $data = $request->validated();

        if (array_key_exists('original_name', $data)) {
            $data['original_name'] = $this->normalizeOriginalName($photo, $data['original_name']);
        }

        $photo->update($data);
        $this->invalidateSeriesCaches($series);

        return response()->json([
            'data' => $photo->fresh(),
        ]);
    }

    public function destroy(Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);
        $this->authorize('delete', $photo);

        $disk = config('filesystems.default');
        Storage::disk($disk)->delete($photo->path);

        $photo->delete();
        $this->touchSeriesForCache($series);
        if (! $series->photos()->exists()) {
            $series->tags()->detach();
        }

        $this->invalidateSeriesCaches($series);

        return response()->json(status: 204);
    }

    private function ensureSeriesPhoto(Series $series, Photo $photo): void
    {
        if ($photo->series_id !== $series->id) {
            abort(404);
        }
    }

    private function normalizeOriginalName(Photo $photo, string $input): string
    {
        $rawName = trim(pathinfo($input, PATHINFO_FILENAME));
        $baseName = $this->normalizeBaseName($rawName);
        $extension = $this->resolveLockedExtension($photo);

        $maxBaseLength = max(1, 255 - strlen($extension) - 1);
        if (strlen($baseName) > $maxBaseLength) {
            $baseName = substr($baseName, 0, $maxBaseLength);
        }

        return "{$baseName}.{$extension}";
    }

    private function normalizeBaseName(string $rawName): string
    {
        if ($rawName === '') {
            return 'file';
        }

        if (preg_match('/^[A-Za-z0-9]+$/', $rawName) === 1) {
            return $rawName;
        }

        $ascii = Str::ascii($rawName);
        $words = preg_replace('/[^A-Za-z0-9]+/', ' ', $ascii) ?? '';
        $camel = Str::camel(trim($words));

        return $camel !== '' ? $camel : 'file';
    }

    private function resolveLockedExtension(Photo $photo): string
    {
        $fromOriginal = strtolower(pathinfo((string) $photo->original_name, PATHINFO_EXTENSION));
        if ($fromOriginal !== '') {
            return $fromOriginal;
        }

        $fromPath = strtolower(pathinfo((string) $photo->path, PATHINFO_EXTENSION));

        return $fromPath !== '' ? $fromPath : 'jpg';
    }

    /**
     * @return array{processed:int, failed:int}
     */
    private function rebuildSeriesTagsFromPhotos(Series $series): array
    {
        $disk = config('filesystems.default');
        $processed = 0;
        $failed = 0;
        $allTagNames = [];

        $series->photos()
            ->orderBy('id')
            ->chunkById(100, function ($photos) use ($series, $disk, &$processed, &$failed, &$allTagNames): void {
                foreach ($photos as $photo) {
                    try {
                        $allTagNames = [
                            ...$allTagNames,
                            ...$this->photoAutoTagger->detectTagsForPhoto($photo, $disk, $series),
                        ];
                        $processed++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }
            });

        $this->photoAutoTagger->syncSeriesTags($series, $allTagNames, true);

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    private function invalidateSeriesCaches(Series $series): void
    {
        SeriesResponseCache::bumpUser((int) $series->user_id);
        SeriesResponseCache::bumpSeries((int) $series->id);
    }

    private function touchSeriesForCache(Series $series): void
    {
        // If-Modified-Since is second-precision; bump timestamp to avoid false 304.
        $series->forceFill([
            'updated_at' => now()->addSecond(),
        ])->saveQuietly();
    }

}
