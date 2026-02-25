<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\Series;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Сервис пакетной загрузки фотографий в конкретную серию.
 */
class PhotoBatchUploader
{
    public function __construct(private ExifMetadataExtractor $exifMetadataExtractor)
    {
    }

    /**
     * @param array<int, UploadedFile> $files
     * @return array{created: array<int, mixed>, failed: array<int, array{original_name: string, error_code: string, message: string}>}
     */
    public function uploadToSeries(Series $series, array $files, string $disk): array
    {
        $directory = "photos/series/{$series->id}";
        $created = [];
        $failed = [];
        $storedPaths = [];

        /** @var UploadedFile $file */
        foreach ($files as $file) {
            $path = null;
            $preparedMetadata = null;

            try {
                // Extract EXIF before writing the file to storage.
                $preparedMetadata = $this->exifMetadataExtractor->extractFromUploadedFile($file);

                $preparedImage = $this->prepareWebImageOrFail($file);
                $path = $this->storeOrFail($preparedImage['binary'], $directory, $disk);
                $storedPaths[] = $path;

                $photo = $series->photos()->create([
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $preparedImage['size'],
                    'mime' => $preparedImage['mime'],
                ]);

                $this->storePreparedMetadata($photo, $file, $preparedMetadata);
                $created[] = $photo;
            } catch (Throwable $e) {
                // Если ошибка произошла после записи файла, удаляем "сироту" из storage.
                if (is_string($path) && $path !== '') {
                    Storage::disk($disk)->delete($path);

                    $storedPaths = array_values(array_filter(
                        $storedPaths,
                        fn (string $storedPath): bool => $storedPath !== $path
                    ));
                }

                $failed[] = [
                    'original_name' => $file->getClientOriginalName(),
                    'error_code' => 'PHOTO_SAVE_FAILED',
                    'message' => 'Photo could not be saved.',
                ];
            }
        }

        if (empty($created) && !empty($storedPaths)) {
            Storage::disk($disk)->delete($storedPaths);
        }

        return [
            'created' => $created,
            'failed' => $failed,
        ];
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function storePreparedMetadata(Photo $photo, UploadedFile $file, ?array $metadata): void
    {
        try {
            if ($metadata === null) {
                return;
            }

            $metadata['optimized_mime'] = 'image/jpeg';

            $size = $file->getSize();
            if ($size !== false && is_numeric($size)) {
                $metadata['source_file_size'] = max(0, (int) $size);
            }

            $photo->metadata()->updateOrCreate([], $metadata);
        } catch (Throwable $e) {
            Log::warning('Failed to extract EXIF metadata for uploaded photo.', [
                'photo_id' => (int) $photo->id,
                'original_name' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{binary:string, mime:string, size:int}
     */
    private function prepareWebImageOrFail(UploadedFile $file): array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new RuntimeException('GD image functions are unavailable.');
        }

        $realPath = $file->getRealPath();
        if (!is_string($realPath) || trim($realPath) === '') {
            throw new RuntimeException('Uploaded file temp path is invalid.');
        }

        $sourceBinary = @file_get_contents($realPath);
        if (!is_string($sourceBinary) || $sourceBinary === '') {
            throw new RuntimeException('Failed to read uploaded file.');
        }

        $image = @imagecreatefromstring($sourceBinary);
        if ($image === false) {
            throw new RuntimeException('Uploaded file is not a decodable image.');
        }

        $maxBytes = max(256000, (int) config('photo_processing.upload_max_bytes', 2 * 1024 * 1024));
        $maxDimension = max(640, (int) config('photo_processing.upload_max_dimension', 2560));
        $minDimension = max(320, (int) config('photo_processing.upload_min_dimension', 900));
        $qualityStart = max(40, min(100, (int) config('photo_processing.upload_jpeg_quality_start', 88)));
        $qualityMin = max(25, min(95, (int) config('photo_processing.upload_jpeg_quality_min', 45)));

        try {
            $sourceWidth = imagesx($image);
            $sourceHeight = imagesy($image);
            if ($sourceWidth <= 0 || $sourceHeight <= 0) {
                throw new RuntimeException('Uploaded image has invalid dimensions.');
            }

            $scale = min(1, $maxDimension / max($sourceWidth, $sourceHeight));
            $targetWidth = max(1, (int) round($sourceWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));

            while (true) {
                $canvas = $this->resampleToCanvas($image, $sourceWidth, $sourceHeight, $targetWidth, $targetHeight);

                try {
                    for ($quality = $qualityStart; $quality >= $qualityMin; $quality -= 5) {
                        $encoded = $this->encodeJpegOrFail($canvas, $quality);
                        $encodedSize = strlen($encoded);

                        if ($encodedSize <= $maxBytes) {
                            return [
                                'binary' => $encoded,
                                'mime' => 'image/jpeg',
                                'size' => $encodedSize,
                            ];
                        }
                    }
                } finally {
                    imagedestroy($canvas);
                }

                if (max($targetWidth, $targetHeight) <= $minDimension) {
                    break;
                }

                $nextWidth = max($minDimension, (int) round($targetWidth * 0.85));
                $nextHeight = max($minDimension, (int) round($targetHeight * 0.85));
                if ($nextWidth === $targetWidth && $nextHeight === $targetHeight) {
                    break;
                }

                $targetWidth = $nextWidth;
                $targetHeight = $nextHeight;
            }
        } finally {
            imagedestroy($image);
        }

        throw new RuntimeException('Image could not be optimized to configured max size.');
    }

    private function resampleToCanvas($source, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight)
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            throw new RuntimeException('Failed to allocate image canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        return $canvas;
    }

    private function encodeJpegOrFail($canvas, int $quality): string
    {
        ob_start();
        $ok = imagejpeg($canvas, null, $quality);
        $encoded = ob_get_clean();

        if (!$ok || !is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Failed to encode optimized image.');
        }

        return $encoded;
    }

    private function storeOrFail(string $binary, string $directoryPath, string $disk): string
    {
        $path = rtrim($directoryPath, '/').'/'.Str::uuid()->toString().'.jpg';
        $stored = Storage::disk($disk)->put($path, $binary);

        if ($stored !== true) {
            throw new RuntimeException('Failed to store uploaded file.');
        }

        return $path;
    }
}
