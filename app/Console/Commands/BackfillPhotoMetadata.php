<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\ExifMetadataExtractor;
use Illuminate\Console\Command;

class BackfillPhotoMetadata extends Command
{
    protected $signature = 'photos:metadata:backfill
        {--from-id=0 : Process photos with id greater than this value}
        {--limit=500 : Maximum number of photos to process}
        {--disk= : Storage disk with original photos (default: filesystems.default)}
        {--dry-run : Analyze metadata without writing to the database}';

    protected $description = 'Backfill EXIF metadata for stored photos.';

    public function __construct(private ExifMetadataExtractor $exifMetadataExtractor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $fromId = max(0, (int) $this->option('from-id'));
        $limit = max(1, (int) $this->option('limit'));
        $disk = (string) ($this->option('disk') ?: config('filesystems.default'));
        $dryRun = (bool) $this->option('dry-run');

        $photos = Photo::query()
            ->where('id', '>', $fromId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($photos->isEmpty()) {
            $this->info('No photos found for the selected range.');
            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($photos as $photo) {
            $processed++;
            try {
                $metadata = $this->exifMetadataExtractor->extractFromStoragePath($disk, (string) $photo->path);
                if ($metadata === null) {
                    $skipped++;
                    continue;
                }

                if (!array_key_exists('source_file_size', $metadata) && is_numeric($photo->size)) {
                    $metadata['source_file_size'] = max(0, (int) $photo->size);
                }

                if (!$dryRun) {
                    $photo->metadata()->updateOrCreate([], $metadata);
                }

                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Photo #{$photo->id}: {$e->getMessage()}");
            }
        }

        $this->table(
            ['from_id', 'limit', 'disk', 'processed', 'updated', 'skipped', 'failed', 'dry_run'],
            [[
                'from_id' => $fromId,
                'limit' => $limit,
                'disk' => $disk,
                'processed' => $processed,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'dry_run' => $dryRun ? 'yes' : 'no',
            ]]
        );

        return self::SUCCESS;
    }
}
