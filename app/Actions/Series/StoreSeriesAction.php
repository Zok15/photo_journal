<?php

namespace App\Actions\Series;

use App\Jobs\ModerateSeriesContent;
use App\Jobs\ProcessSeries;
use App\Jobs\SyncSeriesAutoTags;
use App\Models\Series;
use App\Services\PhotoBatchUploader;
use App\Services\Series\SeriesCacheService;

class StoreSeriesAction
{
    public function __construct(
        private PhotoBatchUploader $photoBatchUploader,
        private SeriesCacheService $seriesCacheService
    ) {
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<int, \Illuminate\Http\UploadedFile> $files
     * @return array{status:int, payload:array<string,mixed>}
     */
    public function execute(int $userId, array $validated, array $files, string $disk, bool $deferPostUploadJobs = false): array
    {
        $requestedPublic = (bool) ($validated['is_public'] ?? false);

        $series = Series::create([
            'user_id' => $userId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'is_public' => false,
            'publication_status' => $requestedPublic
                ? Series::PUBLICATION_PENDING_MODERATION
                : Series::PUBLICATION_DRAFT,
            'moderation_status' => $requestedPublic
                ? Series::MODERATION_PENDING
                : Series::MODERATION_APPROVED,
            'publication_requested_at' => $requestedPublic ? now() : null,
            'moderation_reason' => null,
            'moderation_labels' => [],
            'moderated_at' => null,
            'moderated_by' => null,
        ]);

        $uploadResult = $this->photoBatchUploader->uploadToSeries($series, $files, $disk);
        $created = $uploadResult['created'];
        $failed = $uploadResult['failed'];

        if (count($created) === 0) {
            $series->delete();

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

            if ($requestedPublic) {
                ModerateSeriesContent::dispatch($series->id)
                    ->onQueue((string) config('queue.moderation_queue', 'moderation'));
            }
        }

        $this->seriesCacheService->invalidate($userId, (int) $series->id);

        return [
            'status' => 201,
            'payload' => [
                'id' => $series->id,
                'slug' => $series->slug,
                'status' => 'queued',
                'photos_created' => $created,
                'photos_failed' => $failed,
                'tags_sync' => $deferPostUploadJobs ? 'deferred' : 'queued',
                'publication_status' => $series->publication_status,
                'moderation_status' => $series->moderation_status,
            ],
        ];
    }
}
