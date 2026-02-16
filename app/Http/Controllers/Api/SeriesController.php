<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeriesWithPhotosRequest;
use App\Jobs\ProcessSeries;
use App\Models\Series;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SeriesController extends Controller
{
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
        $directory = 'photos/series';
        $files = $request->file('photos', []);
        $series = Series::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
        ]);

        $directoryPath = $directory.'/'.$series->id;
        $storedPaths = [];
        $created = [];
        $failed = [];

        /** @var UploadedFile $file */
        foreach ($files as $file) {
            try {
                $path = $this->storeOrFail($file, $directoryPath, $disk);
                $storedPaths[] = $path;

                $created[] = $series->photos()->create([
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getClientMimeType(),
                ]);
            } catch (\Throwable $e) {
                $failed[] = [
                    'original_name' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (count($created) === 0) {
            if (!empty($storedPaths)) {
                Storage::disk($disk)->delete($storedPaths);
            }

            $series->delete();

            return response()->json([
                'message' => 'No photos were saved.',
                'failed' => $failed,
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

    public function show(Series $series): JsonResponse
    {
        $series->load(['photos.tags']);
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

    private function storeOrFail(UploadedFile $file, string $directoryPath, string $disk): string
    {
        $path = $file->store($directoryPath, $disk);

        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Failed to store uploaded file.');
        }

        return $path;
    }
}
