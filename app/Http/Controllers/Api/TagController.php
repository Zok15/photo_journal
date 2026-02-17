<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTagRequest;
use App\Http\Requests\UpdateTagRequest;
use App\Models\Series;
use App\Models\Tag;
use App\Support\SeriesResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function suggest(Request $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 8);

        if ($query === '') {
            return response()->json(['data' => []]);
        }

        $prefix = mb_substr($query, 0, 64);

        $tags = Tag::query()
            ->select(['id', 'name'])
            ->where('name', 'like', $prefix.'%')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $tags,
        ]);
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $tag = Tag::query()->create($request->validated());

        return response()->json([
            'data' => $tag,
        ], 201);
    }

    public function update(UpdateTagRequest $request, Tag $tag): JsonResponse
    {
        $this->authorize('update', $tag);

        $tag->update($request->validated());
        $this->invalidateRelatedSeriesCaches($tag);

        return response()->json([
            'data' => $tag->fresh(),
        ]);
    }

    private function invalidateRelatedSeriesCaches(Tag $tag): void
    {
        $seriesIds = Series::query()
            ->whereHas('tags', fn ($query) => $query->where('tags.id', $tag->id))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($seriesIds->isEmpty()) {
            return;
        }

        $userIds = Series::query()
            ->whereIn('id', $seriesIds->all())
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();

        foreach ($userIds as $userId) {
            SeriesResponseCache::bumpUser($userId);
        }

        foreach ($seriesIds as $seriesId) {
            SeriesResponseCache::bumpSeries($seriesId);
        }
    }
}
