<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeriesWithPhotosRequest;
use App\Jobs\ProcessSeries;
use App\Models\Series;
use App\Services\PhotoBatchUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        ]);

        $perPage = $validated['per_page'] ?? 15;

        $series = Series::query()
            ->where('user_id', $request->user()->id)
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
                    ->with('tags')
                    ->orderByRaw('sort_order IS NULL')
                    ->orderBy('sort_order')
                    ->latest()
                    ->limit($limit),
            ]);

            $series->photos->each(function ($photo) use ($disk): void {
                $photo->setAttribute('preview_url', $this->resolvePhotoPreviewUrl($disk, $photo->path));
            });
        }

        $series->loadCount('photos');

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
            'data' => $series->fresh()->loadCount('photos'),
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

}
