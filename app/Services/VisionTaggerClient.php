<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VisionTaggerClient
{
    public function isEnabled(): bool
    {
        return (bool) config('vision.enabled');
    }

    public function isHealthy(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $base = (string) config('vision.url');
            $healthUrl = preg_replace('#/tag$#', '/health', $base) ?: $base;
            $response = Http::timeout(2)->acceptJson()->get($healthUrl);

            return $response->ok() && ($response->json('ok') === true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    public function detectTags(string $disk, string $path): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $absolutePath = $this->resolveAbsolutePath($disk, $path);
        if ($absolutePath === null) {
            return [];
        }

        try {
            $response = Http::timeout((int) config('vision.timeout_seconds', 20))
                ->acceptJson()
                ->attach('image', file_get_contents($absolutePath), basename($absolutePath))
                ->post((string) config('vision.url'));

            if (!$response->ok()) {
                return [];
            }

            $rawTags = $response->json('tags', []);

            return collect(is_array($rawTags) ? $rawTags : [])
                ->filter(fn ($tag): bool => is_string($tag) && trim($tag) !== '')
                ->map(fn (string $tag): string => trim($tag))
                ->unique()
                ->take((int) config('vision.max_tags', 8))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveAbsolutePath(string $disk, string $path): ?string
    {
        try {
            $absolutePath = Storage::disk($disk)->path($path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($absolutePath) && $absolutePath !== '' && is_file($absolutePath) ? $absolutePath : null;
    }
}
