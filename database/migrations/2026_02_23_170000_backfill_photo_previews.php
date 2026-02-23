<?php

use App\Models\Photo;
use App\Services\Series\SeriesPreviewGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('photos', 'preview_path')) {
            return;
        }

        $disk = (string) config('filesystems.default');
        $generator = app(SeriesPreviewGenerator::class);

        Photo::query()
            ->select(['id', 'path', 'preview_path', 'mime'])
            ->whereNotNull('path')
            ->where(function ($query): void {
                $query->whereNull('preview_path')->orWhere('preview_path', '');
            })
            ->orderBy('id')
            ->chunkById(100, function ($photos) use ($generator, $disk): void {
                foreach ($photos as $photo) {
                    $generator->generateForPhoto($photo, $disk);
                }
            });
    }

    public function down(): void
    {
        // Intentionally no-op: down migration should not delete generated previews.
    }
};
