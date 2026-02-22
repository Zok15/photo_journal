<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Series;
use App\Support\SeriesResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSeriesModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'publication_status' => ['nullable', 'in:draft,pending_moderation,published,rejected'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $status = (string) ($validated['publication_status'] ?? Series::PUBLICATION_PENDING_MODERATION);

        $series = Series::query()
            ->where('publication_status', $status)
            ->with('user:id,name,email')
            ->withCount('photos')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($series);
    }

    public function publish(Request $request, Series $series): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $series->forceFill([
            'is_public' => true,
            'publication_status' => Series::PUBLICATION_PUBLISHED,
            'moderation_status' => Series::MODERATION_MANUAL_APPROVED,
            'moderation_reason' => $data['reason'] ?? 'Manually approved by admin.',
            'moderation_labels' => [],
            'publication_requested_at' => $series->publication_requested_at ?? now(),
            'moderated_at' => now(),
            'moderated_by' => (int) $request->user()->id,
        ])->save();

        $this->invalidateSeriesCaches($series);

        return response()->json([
            'data' => $series->fresh()->loadCount('photos')->load('tags'),
        ]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['super_admin', 'moderator']), 403);
    }

    private function invalidateSeriesCaches(Series $series): void
    {
        SeriesResponseCache::bumpUser((int) $series->user_id);
        SeriesResponseCache::bumpSeries((int) $series->id);
    }
}
