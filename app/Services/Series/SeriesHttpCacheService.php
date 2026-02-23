<?php

namespace App\Services\Series;

use App\Support\SeriesResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SeriesHttpCacheService
{
    public function buildSeriesShowCacheKey(int $userId, int $seriesId, array $validated): string
    {
        $normalized = [
            'include_photos' => (bool) ($validated['include_photos'] ?? false),
            'photos_limit' => (int) ($validated['photos_limit'] ?? 30),
        ];

        return SeriesResponseCache::showKey($userId, $seriesId, $normalized);
    }

    public function respondWithConditionalJson(
        Request $request,
        array $payload,
        string|Carbon|null $lastModified = null,
        string $cacheControl = 'private, no-cache, max-age=0, must-revalidate',
        string $vary = 'Authorization, Accept'
    ): JsonResponse {
        $etagHash = sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $lastModifiedAt = $lastModified instanceof Carbon
            ? $lastModified
            : ($lastModified !== null ? Carbon::parse($lastModified) : null);

        if ($lastModifiedAt !== null && $this->ifModifiedSinceNotChanged($request, $lastModifiedAt)) {
            return response()
                ->json([], 304)
                ->setEtag($etagHash)
                ->setLastModified($lastModifiedAt)
                ->header('Cache-Control', $cacheControl)
                ->header('Vary', $vary);
        }

        if ($this->ifNoneMatchMatches($request, $etagHash)) {
            $response = response()
                ->json([], 304)
                ->setEtag($etagHash)
                ->header('Cache-Control', $cacheControl)
                ->header('Vary', $vary);

            if ($lastModifiedAt !== null) {
                $response->setLastModified($lastModifiedAt);
            }

            return $response;
        }

        $response = response()
            ->json($payload)
            ->setEtag($etagHash)
            ->header('Cache-Control', $cacheControl)
            ->header('Vary', $vary);

        if ($lastModifiedAt !== null) {
            $response->setLastModified($lastModifiedAt);
        }

        return $response;
    }

    public function latestTimestamp(string|Carbon|null ...$candidates): ?Carbon
    {
        $result = null;

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            try {
                $timestamp = $candidate instanceof Carbon ? $candidate : Carbon::parse((string) $candidate);
            } catch (\Throwable) {
                continue;
            }

            if ($result === null || $timestamp->gt($result)) {
                $result = $timestamp;
            }
        }

        return $result;
    }

    public function cachedPayload(string $cacheKey, \Closure $resolver, string $scope): array
    {
        $startedAt = microtime(true);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            $this->logCacheMetric($scope, 'hit', $startedAt);

            return $cached;
        }

        $payload = $resolver();
        Cache::put($cacheKey, $payload, now()->addSeconds($this->responseCacheTtlSeconds()));
        $this->logCacheMetric($scope, 'miss', $startedAt);

        return $payload;
    }

    private function responseCacheTtlSeconds(): int
    {
        return max(5, (int) config('app.series_response_cache_ttl_seconds', 20));
    }

    private function ifNoneMatchMatches(Request $request, string $etagHash): bool
    {
        $header = trim((string) $request->header('If-None-Match', ''));
        if ($header === '') {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        $quotedEtag = '"'.$etagHash.'"';
        $weakQuotedEtag = 'W/'.$quotedEtag;

        return collect(explode(',', $header))
            ->map(static fn (string $value): string => trim($value))
            ->contains(static fn (string $value): bool => in_array($value, [$quotedEtag, $weakQuotedEtag], true));
    }

    private function ifModifiedSinceNotChanged(Request $request, Carbon $lastModified): bool
    {
        $value = trim((string) $request->header('If-Modified-Since', ''));
        if ($value === '') {
            return false;
        }

        try {
            $since = Carbon::parse($value);
        } catch (\Throwable) {
            return false;
        }

        return $lastModified->lessThanOrEqualTo($since);
    }

    private function logCacheMetric(string $scope, string $result, float $startedAt): void
    {
        Log::info('series.response_cache', [
            'scope' => $scope,
            'result' => $result,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
