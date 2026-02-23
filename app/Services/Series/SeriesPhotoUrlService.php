<?php

namespace App\Services\Series;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class SeriesPhotoUrlService
{
    public function resolvePreviewUrl(string $disk, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $storage = Storage::disk($disk);
        $useSignedUrls = (bool) config('photo_processing.preview_signed_urls', false);
        $isPrivateLocalDisk = $this->isPrivateLocalDisk($disk);

        if ($isPrivateLocalDisk) {
            $useSignedUrls = true;
        }

        if ($useSignedUrls) {
            $ttlMinutes = max(1, (int) config('photo_processing.preview_signed_ttl_minutes', 30));

            try {
                return $storage->temporaryUrl($path, Carbon::now()->addMinutes($ttlMinutes));
            } catch (\Throwable) {
                return $storage->url($path);
            }
        }

        return $storage->url($path);
    }

    public function resolvePublicUrl(string $disk, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if ($this->isPrivateLocalDisk($disk)) {
            return null;
        }

        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isPrivateLocalDisk(string $disk): bool
    {
        $driver = (string) config("filesystems.disks.{$disk}.driver", '');
        $root = (string) config("filesystems.disks.{$disk}.root", '');

        return $driver === 'local' && $root === storage_path('app/private');
    }
}
