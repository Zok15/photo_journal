<?php

namespace App\Http\Controllers\Api;

use App\Actions\SeriesPhoto\DestroySeriesPhotoAction;
use App\Actions\SeriesPhoto\RebuildSeriesTagsAction;
use App\Actions\SeriesPhoto\ReorderSeriesPhotosAction;
use App\Actions\SeriesPhoto\StoreSeriesPhotosAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListSeriesPhotosRequest;
use App\Http\Requests\StoreSeriesPhotosRequest;
use App\Http\Requests\UpdateSeriesPhotoRequest;
use App\Models\Photo;
use App\Models\Series;
use App\Services\Series\SeriesCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Контроллер фотографий внутри серии.
 *
 * Отвечает за:
 * - загрузку и удаление фото;
 * - сортировку;
 * - переименование;
 * - пересборку тегов для серии.
 */
class SeriesPhotoController extends Controller
{
    public function __construct(
        private StoreSeriesPhotosAction $storeSeriesPhotosAction,
        private ReorderSeriesPhotosAction $reorderSeriesPhotosAction,
        private RebuildSeriesTagsAction $rebuildSeriesTagsAction,
        private DestroySeriesPhotoAction $destroySeriesPhotoAction,
        private SeriesCacheService $seriesCacheService
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

        $result = $this->storeSeriesPhotosAction->execute(
            $series,
            $request->file('photos', []),
            (string) config('filesystems.default')
        );

        return response()->json($result['payload'], $result['status']);
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

        $result = $this->reorderSeriesPhotosAction->execute(
            $series,
            array_map('intval', $data['photo_ids'])
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function retag(Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $result = $this->rebuildSeriesTagsAction->execute($series);
        $this->seriesCacheService->invalidateForSeries($series);

        return response()->json([
            'data' => $result,
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
        $this->seriesCacheService->invalidateForSeries($series);

        return response()->json([
            'data' => $photo->fresh(),
        ]);
    }

    public function destroy(Series $series, Photo $photo): JsonResponse
    {
        $this->ensureSeriesPhoto($series, $photo);
        $this->authorize('delete', $photo);

        $this->destroySeriesPhotoAction->execute($series, $photo, (string) config('filesystems.default'));

        return response()->json(status: 204);
    }

    private function ensureSeriesPhoto(Series $series, Photo $photo): void
    {
        // Базовая защита от доступа к фото из чужой серии.
        if ($photo->series_id !== $series->id) {
            abort(404);
        }
    }

    private function normalizeOriginalName(Photo $photo, string $input): string
    {
        // Разрешаем менять только basename, расширение фиксируем по текущему файлу.
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

}
