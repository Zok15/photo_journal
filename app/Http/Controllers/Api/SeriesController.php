<?php

namespace App\Http\Controllers\Api;

use App\Actions\Series\AttachSeriesTagsAction;
use App\Actions\Series\DestroySeriesAction;
use App\Actions\Series\DetachSeriesTagAction;
use App\Actions\Series\StoreSeriesAction;
use App\Actions\Series\UpdateSeriesAction;
use App\Actions\SeriesRead\ListPublicSeriesAction;
use App\Actions\SeriesRead\ListSeriesAction;
use App\Actions\SeriesRead\ShowPublicSeriesAction;
use App\Actions\SeriesRead\ShowSeriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Series\AttachSeriesTagsRequest;
use App\Http\Requests\Series\ListPublicSeriesRequest;
use App\Http\Requests\Series\ListSeriesRequest;
use App\Http\Requests\Series\ShowPublicSeriesRequest;
use App\Http\Requests\Series\ShowSeriesRequest;
use App\Http\Requests\Series\UpdateSeriesRequest;
use App\Http\Requests\StoreSeriesWithPhotosRequest;
use App\Models\Series;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;

class SeriesController extends Controller
{
    public function __construct(
        private ListSeriesAction $listSeriesAction,
        private ListPublicSeriesAction $listPublicSeriesAction,
        private ShowSeriesAction $showSeriesAction,
        private ShowPublicSeriesAction $showPublicSeriesAction,
        private StoreSeriesAction $storeSeriesAction,
        private UpdateSeriesAction $updateSeriesAction,
        private DestroySeriesAction $destroySeriesAction,
        private AttachSeriesTagsAction $attachSeriesTagsAction,
        private DetachSeriesTagAction $detachSeriesTagAction
    ) {
    }

    public function index(ListSeriesRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Series::class);

        return $this->listSeriesAction->execute($request, $request->validated(), (int) $request->user()->id);
    }

    public function publicIndex(ListPublicSeriesRequest $request): JsonResponse
    {
        return $this->listPublicSeriesAction->execute($request, $request->validated());
    }

    public function publicShow(ShowPublicSeriesRequest $request, Series $series): JsonResponse
    {
        return $this->showPublicSeriesAction->execute($request, $series, $request->validated());
    }

    public function store(StoreSeriesWithPhotosRequest $request): JsonResponse
    {
        $this->authorize('create', Series::class);

        $result = $this->storeSeriesAction->execute(
            (int) $request->user()->id,
            $request->validated(),
            $request->file('photos', []),
            (string) config('filesystems.default'),
            (bool) $request->boolean('defer_post_upload_jobs')
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function show(ShowSeriesRequest $request, Series $series): JsonResponse
    {
        $this->authorize('view', $series);

        return $this->showSeriesAction->execute($request, $series, $request->validated());
    }

    public function update(UpdateSeriesRequest $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $result = $this->updateSeriesAction->execute($series, $request->validated());

        return response()->json([
            'data' => $result['data'],
        ]);
    }

    public function destroy(Series $series): JsonResponse
    {
        $this->authorize('delete', $series);

        $this->destroySeriesAction->execute($series, (string) config('filesystems.default'));

        return response()->json(status: 204);
    }

    public function attachTags(AttachSeriesTagsRequest $request, Series $series): JsonResponse
    {
        $this->authorize('update', $series);

        $result = $this->attachSeriesTagsAction->execute($series, $request->validated('tags'));

        return response()->json($result['payload'], $result['status']);
    }

    public function detachTag(Series $series, Tag $tag): JsonResponse
    {
        $this->authorize('update', $series);

        $result = $this->detachSeriesTagAction->execute($series, $tag);

        return response()->json([
            'data' => $result['data'],
        ]);
    }
}
