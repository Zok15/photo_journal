<?php

namespace App\Services;

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
    public function __construct(private PhotoAutoTagger $photoAutoTagger)
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

            try {
                $path = $this->storeOrFail($file, $directory, $disk);
                $storedPaths[] = $path;

                $photo = $series->photos()->create([
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getClientMimeType(),
                ]);

                $created[] = $photo;

                try {
                    // Автотеги — вспомогательная логика: не должны ронять загрузку фото.
                    $this->photoAutoTagger->attachPhotoTagsToSeries($series, $photo, $disk);
                } catch (Throwable $e) {
                    // Auto-tagging must not break successful photo uploads.
                    Log::warning('Photo auto-tagging failed', [
                        'photo_id' => $photo->id,
                        'series_id' => $series->id,
                        'error' => $e->getMessage(),
                    ]);
                }
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

    private function storeOrFail(UploadedFile $file, string $directoryPath, string $disk): string
    {
        $path = $file->store($directoryPath, $disk);

        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Failed to store uploaded file.');
        }

        return $path;
    }
}
