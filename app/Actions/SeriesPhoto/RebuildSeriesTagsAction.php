<?php

namespace App\Actions\SeriesPhoto;

use App\Models\Series;
use App\Services\PhotoAutoTagger;

class RebuildSeriesTagsAction
{
    public function __construct(private PhotoAutoTagger $photoAutoTagger)
    {
    }

    /**
     * @return array{processed:int, failed:int, tags_count:int, vision_enabled:bool, vision_healthy:bool}
     */
    public function execute(Series $series): array
    {
        $disk = config('filesystems.default');
        $processed = 0;
        $failed = 0;
        $allTagNames = [];

        $series->photos()
            ->orderBy('id')
            ->chunkById(100, function ($photos) use ($series, $disk, &$processed, &$failed, &$allTagNames): void {
                foreach ($photos as $photo) {
                    try {
                        $allTagNames = [
                            ...$allTagNames,
                            ...$this->photoAutoTagger->detectTagsForPhoto($photo, $disk, $series),
                        ];
                        $processed++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }
            });

        $this->photoAutoTagger->syncSeriesTags($series, $allTagNames, true);

        return [
            'processed' => $processed,
            'failed' => $failed,
            'tags_count' => $series->tags()->count(),
            'vision_enabled' => $this->photoAutoTagger->visionEnabled(),
            'vision_healthy' => $this->photoAutoTagger->visionHealthy(),
        ];
    }
}
