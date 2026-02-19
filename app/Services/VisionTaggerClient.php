<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * HTTP-клиент внешнего vision-сервиса для определения тегов по изображению.
 */
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
            // Для URL .../tag автоматически проверяем .../health.
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
    public function detectTags(string $disk, string $path, array $tagHints = []): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        ['local_path' => $localPath, 'cleanup' => $cleanup] = $this->resolveLocalPath($disk, $path);
        if ($localPath === null) {
            return [];
        }

        $preparedHints = collect($tagHints)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->take((int) config('vision.max_hints', 20))
            ->values()
            ->all();

        try {
            $response = Http::timeout((int) config('vision.timeout_seconds', 20))
                ->acceptJson()
                ->attach('image', file_get_contents($localPath), basename($path))
                ->post((string) config('vision.url'), [
                    // Подсказки передаем JSON-строкой, чтобы сервис мог улучшить релевантность тегов.
                    'tag_hints' => $preparedHints === [] ? '' : json_encode($preparedHints, JSON_UNESCAPED_UNICODE),
                ]);

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
        } finally {
            if ($cleanup && is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }

    /**
     * @return array{local_path: ?string, cleanup: bool}
     */
    private function resolveLocalPath(string $disk, string $path): array
    {
        $absolutePath = $this->resolveAbsolutePath($disk, $path);
        if ($absolutePath !== null) {
            return ['local_path' => $absolutePath, 'cleanup' => false];
        }

        $storage = Storage::disk($disk);
        if (!$storage->exists($path)) {
            return ['local_path' => null, 'cleanup' => false];
        }

        $stream = $storage->readStream($path);
        if (!is_resource($stream)) {
            return ['local_path' => null, 'cleanup' => false];
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'vision_');
        if (!is_string($tmpPath) || $tmpPath === '') {
            fclose($stream);

            return ['local_path' => null, 'cleanup' => false];
        }

        $target = fopen($tmpPath, 'wb');
        if (!is_resource($target)) {
            fclose($stream);
            @unlink($tmpPath);

            return ['local_path' => null, 'cleanup' => false];
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        return ['local_path' => $tmpPath, 'cleanup' => true];
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
