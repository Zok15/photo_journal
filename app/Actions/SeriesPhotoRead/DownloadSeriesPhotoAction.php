<?php

namespace App\Actions\SeriesPhotoRead;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadSeriesPhotoAction
{
    public function execute(Photo $photo, string $disk): StreamedResponse
    {
        $storage = Storage::disk($disk);

        abort_unless($storage->exists($photo->path), 404);

        $extension = strtolower(pathinfo((string) $photo->path, PATHINFO_EXTENSION));
        $fallback = 'photo-'.$photo->id.($extension !== '' ? '.'.$extension : '');
        $downloadName = trim((string) $photo->original_name) !== '' ? (string) $photo->original_name : $fallback;

        return $storage->download($photo->path, $downloadName);
    }
}
