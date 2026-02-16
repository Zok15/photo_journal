<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeriesWithPhotosRequest;
use App\Jobs\ProcessSeries;
use App\Models\Series;
use App\Services\PhotoBatchUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SeriesController extends Controller
{
    public function __construct(private PhotoBatchUploader $photoBatchUploader) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = $validated['per_page'] ?? 15;

        $series = Series::query()
            ->withCount('photos')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($series);
    }

    public function store(StoreSeriesWithPhotosRequest $request): JsonResponse
    {
        $data = $request->validated();

        $disk = config('filesystems.default');
        $files = $request->file('photos', []);
        $series = Series::create([
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
        $validated = $request->validate([
            'include_photos' => ['nullable', 'boolean'],
            'photos_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($request->boolean('include_photos')) {
            $limit = $validated['photos_limit'] ?? 30;

            $series->load([
                'photos' => fn ($query) => $query
                    ->with('tags')
                    ->latest()
                    ->limit($limit),
            ]);
        }

        $series->loadCount('photos');

        return response()->json([
            'data' => $series,
        ]);
    }

    public function update(Request $request, Series $series): JsonResponse
    {
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

}
