<?php

namespace App\Http\Controllers\Api;

use App\Actions\SeriesPhoto\DestroySeriesPhotoAction;
use App\Actions\SeriesPhoto\RebuildSeriesTagsAction;
use App\Actions\SeriesPhoto\ReorderSeriesPhotosAction;
use App\Actions\SeriesPhoto\StoreSeriesPhotosAction;
use App\Actions\SeriesPhotoRead\DownloadSeriesPhotoAction;
use App\Actions\SeriesPhotoRead\ListSeriesPhotosAction;
use App\Actions\SeriesPhotoRead\ShowSeriesPhotoAction;
use App\Actions\SeriesPhotoRead\UpdateSeriesPhotoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListSeriesPhotosRequest;
use App\Http\Requests\SeriesPhoto\ReorderSeriesPhotosRequest;
use App\Http\Requests\StoreSeriesPhotosRequest;
use App\Http\Requests\UpdateSeriesPhotoRequest;
use App\Models\Photo;
use App\Models\Series;
use App\Services\Series\SeriesCacheService;
use App\Services\Series\SeriesPhotoOwnershipService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeriesPhotoController extends Controller
{
    public function __construct(
        private ListSeriesPhotosAction $listSeriesPhotosAction,
        private StoreSeriesPhotosAction $storeSeriesPhotosAction,
        private ShowSeriesPhotoAction $showSeriesPhotoAction,
        private DownloadSeriesPhotoAction $downloadSeriesPhotoAction,
        private ReorderSeriesPhotosAction $reorderSeriesPhotosAction,
        private RebuildSeriesTagsAction $rebuildSeriesTagsAction,
        private UpdateSeriesPhotoAction $updateSeriesPhotoAction,
        private DestroySeriesPhotoAction $destroySeriesPhotoAction,
        private SeriesPhotoOwnershipService $seriesPhotoOwnershipService,
        private SeriesCacheService $seriesCacheService
    ) {
    }

    public function index(ListSeriesPhotosRequest $request, Series $series): JsonResponse
    {
        $this->authorize('view', $series);

        return response()->json($this->listSeriesPhotosAction->execute($series, $request->validated()));
    }

    public function store(StoreSeriesPhotosRequest $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $result = $this->storeSeriesPhotosAction->execute(
            $series,
            $request->file('photos', []),
            (string) config('filesystems.default'),
            (bool) $request->boolean('defer_post_upload_jobs')
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function show(Series $series, Photo $photo): JsonResponse
    {
        $this->seriesPhotoOwnershipService->ensureBelongsToSeries($series, $photo);
        $this->authorize('view', $photo);

        return response()->json($this->showSeriesPhotoAction->execute($photo));
    }

    public function download(Series $series, Photo $photo): StreamedResponse
    {
        $this->seriesPhotoOwnershipService->ensureBelongsToSeries($series, $photo);
        $this->authorize('view', $photo);

        return $this->downloadSeriesPhotoAction->execute($photo, (string) config('filesystems.default'));
    }

    public function reorder(ReorderSeriesPhotosRequest $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $result = $this->reorderSeriesPhotosAction->execute(
            $series,
            array_map('intval', $request->validated('photo_ids'))
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
        $this->seriesPhotoOwnershipService->ensureBelongsToSeries($series, $photo);
        $this->authorize('update', $photo);

        return response()->json($this->updateSeriesPhotoAction->execute($series, $photo, $request->validated()));
    }

    public function destroy(Series $series, Photo $photo): JsonResponse
    {
        $this->seriesPhotoOwnershipService->ensureBelongsToSeries($series, $photo);
        $this->authorize('delete', $photo);

        $this->destroySeriesPhotoAction->execute($series, $photo, (string) config('filesystems.default'));

        return response()->json(status: 204);
    }
}
