<?php

namespace App\Actions\SeriesPhoto;

use App\Jobs\ProcessSeries;
use App\Jobs\ModerateSeriesContent;
use App\Jobs\SyncSeriesAutoTags;
use App\Models\Series;
use App\Services\PhotoBatchUploader;
use App\Services\Series\SeriesCacheService;

class StoreSeriesPhotosAction
{
    public function __construct(
        private PhotoBatchUploader $photoBatchUploader,
        private SeriesCacheService $seriesCacheService
    ) {
    }

    /**
     * @param array<int, \Illuminate\Http\UploadedFile> $files
     * @return array{status:int, payload:array<string,mixed>}
     */
    public function execute(Series $series, array $files, string $disk, bool $deferPostUploadJobs = false): array
    {
        $uploadResult = $this->photoBatchUploader->uploadToSeries($series, $files, $disk);
        $created = $uploadResult['created'];
        $failed = $uploadResult['failed'];

        if (count($created) === 0) {
            return [
                'status' => 422,
                'payload' => [
                    'message' => 'No photos were saved.',
                    'photos_failed' => $failed,
                ],
            ];
        }

        if (!$deferPostUploadJobs) {
            ProcessSeries::dispatch($series->id);
            SyncSeriesAutoTags::dispatch($series->id);
            $this->queueModerationIfNeeded($series);
        }
        $this->seriesCacheService->invalidateForSeries($series);

        return [
            'status' => 201,
            'payload' => [
                'photos_created' => $created,
                'photos_failed' => $failed,
                'tags_sync' => $deferPostUploadJobs ? 'deferred' : 'queued',
            ],
        ];
    }

    private function queueModerationIfNeeded(Series $series): void
    {
        $publicationStatus = (string) $series->publication_status;

        if ($publicationStatus !== Series::PUBLICATION_PUBLISHED
            && $publicationStatus !== Series::PUBLICATION_PENDING_MODERATION) {
            return;
        }

        $series->forceFill([
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
            'publication_requested_at' => now(),
            'moderation_reason' => null,
            'moderation_labels' => [],
            'moderated_at' => null,
            'moderated_by' => null,
        ])->save();

        ModerateSeriesContent::dispatch((int) $series->id)
            ->onQueue((string) config('queue.moderation_queue', 'moderation'));
    }
}
