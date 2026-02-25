<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\Series;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

                $path = $this->storeOrFail($file, $directory, $disk);
                $storedPaths[] = $path;

                $photo = $series->photos()->create([
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getClientMimeType(),
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

    private function storeOrFail(UploadedFile $file, string $directoryPath, string $disk): string
    {
        $path = $file->store($directoryPath, $disk);

        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Failed to store uploaded file.');
        }

        return $path;
    }
}
