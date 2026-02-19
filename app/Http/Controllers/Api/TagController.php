<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTagRequest;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API для работы с тегами (список, подсказки, создание).
 */
class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Разрешаем доступ авторизованным пользователям (по политике).
        $this->authorize('create', Tag::class);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $query = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 200);
        $userId = (int) $request->user()->id;

        $tagsQuery = Tag::query()
            ->select(['id', 'name'])
            ->whereHas('series', function ($builder) use ($userId): void {
                $builder->where('series.user_id', $userId);
            })
            ->orderBy('name')
            ->limit($limit);

        if ($query !== '') {
            // Используем prefix-поиск для быстрых подсказок и фильтра.
            $prefix = mb_substr($query, 0, 64);
            $tagsQuery->where('name', 'like', $prefix.'%');
        }

        return response()->json([
            'data' => $tagsQuery->get(),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 8);

        $tags = Tag::query()
            ->select(['id', 'name'])
            ->when($query !== '', function ($builder) use ($query): void {
                $needle = '%'.mb_substr($query, 0, 64).'%';
                $builder->where('name', 'like', $needle);
            }, function ($builder): void {
                $builder
                    ->leftJoin('series_tag', 'series_tag.tag_id', '=', 'tags.id')
                    ->groupBy('tags.id', 'tags.name')
                    ->orderByRaw('COUNT(series_tag.id) DESC')
                    ->orderBy('tags.name');
            })
            ->when($query !== '', function ($builder): void {
                $builder->orderBy('name');
            })
            ->limit($limit)
            ->get(['tags.id', 'tags.name']);

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
}
