<?php

namespace App\Services\Series;

use App\Models\Photo;
use App\Models\Series;
use Illuminate\Support\Facades\Storage;

class SeriesPreviewGenerator
{
    public function generateForSeries(Series $series, string $disk): void
    {
        $series->loadMissing('photos:id,series_id,path,preview_path,mime');

        foreach ($series->photos as $photo) {
            $this->generateForPhoto($photo, $disk);
        }
    }

    public function generateForPhoto(Photo $photo, string $disk): void
    {
        $sourcePath = (string) ($photo->path ?? '');
        if ($sourcePath === '') {
            return;
        }

        $storage = Storage::disk($disk);
        if (! $storage->exists($sourcePath)) {
            return;
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return;
        }

        $targetPath = $this->buildPreviewPath($sourcePath);
        if ($targetPath === '') {
            return;
        }

        if ((string) $photo->preview_path === $targetPath && $storage->exists($targetPath)) {
            return;
        }

        try {
            $binary = $storage->get($sourcePath);
        } catch (\Throwable) {
            return;
        }

        $previewBinary = $this->makeWebpPreview($binary);
        if ($previewBinary === null) {
            return;
        }

        try {
            $storage->put($targetPath, $previewBinary);
        } catch (\Throwable) {
            return;
        }

        $photo->forceFill([
            'preview_path' => $targetPath,
        ])->save();
    }

    private function buildPreviewPath(string $sourcePath): string
    {
        $dir = pathinfo($sourcePath, PATHINFO_DIRNAME);
        $filename = pathinfo($sourcePath, PATHINFO_FILENAME);
        if ($filename === '') {
            return '';
        }

        $maxWidth = max(160, (int) config('photo_processing.preview_max_width', 640));
        $safeDir = $dir === '.' ? '' : rtrim($dir, '/').'/';
        return "{$safeDir}previews/{$filename}_w{$maxWidth}.webp";
    }

    private function makeWebpPreview(string $sourceBinary): ?string
    {
        $image = @imagecreatefromstring($sourceBinary);
        if ($image === false) {
            return null;
        }

        try {
            $sourceWidth = imagesx($image);
            $sourceHeight = imagesy($image);
            if ($sourceWidth <= 0 || $sourceHeight <= 0) {
                return null;
            }

            $maxWidth = max(160, (int) config('photo_processing.preview_max_width', 640));
            $quality = max(30, min(100, (int) config('photo_processing.preview_webp_quality', 72)));

            $targetWidth = min($maxWidth, $sourceWidth);
            $targetHeight = (int) round(($targetWidth / $sourceWidth) * $sourceHeight);
            $targetHeight = max(1, $targetHeight);

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($canvas === false) {
                return null;
            }

            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);

            imagecopyresampled(
                $canvas,
                $image,
                0,
                0,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $sourceWidth,
                $sourceHeight
            );

            ob_start();
            $ok = imagewebp($canvas, null, $quality);
            $binary = ob_get_clean();
            imagedestroy($canvas);

            if (! $ok || ! is_string($binary) || $binary === '') {
                return null;
            }

            return $binary;
        } finally {
            imagedestroy($image);
        }
    }
}
