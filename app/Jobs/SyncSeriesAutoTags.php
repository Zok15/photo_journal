<?php

namespace App\Jobs;

use App\Models\Series;
use App\Services\PhotoAutoTagger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSeriesAutoTags implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $seriesId)
    {
    }

    public function handle(PhotoAutoTagger $photoAutoTagger): void
    {
        $series = Series::query()->find($this->seriesId);
        if ($series === null) {
            return;
        }

        $disk = config('filesystems.default');
        $processed = 0;
        $failed = 0;
        $allTagNames = [];

        $series->photos()
            ->orderBy('id')
            ->chunkById(100, function ($photos) use ($photoAutoTagger, $series, $disk, &$processed, &$failed, &$allTagNames): void {
                foreach ($photos as $photo) {
                    try {
                        $allTagNames = [
                            ...$allTagNames,
                            ...$photoAutoTagger->detectTagsForPhoto($photo, $disk, $series),
                        ];
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('Auto-tagging failed for photo during series sync.', [
                            'series_id' => $series->id,
                            'photo_id' => $photo->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $photoAutoTagger->syncSeriesTags($series, $allTagNames, true);

        Log::info('Series auto tags synced.', [
            'series_id' => $series->id,
            'processed' => $processed,
            'failed' => $failed,
        ]);
    }
}
