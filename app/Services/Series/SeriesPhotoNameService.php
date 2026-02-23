<?php

namespace App\Services\Series;

use App\Models\Photo;
use Illuminate\Support\Str;

class SeriesPhotoNameService
{
    public function normalizeOriginalName(Photo $photo, string $input): string
    {
        $rawName = trim(pathinfo($input, PATHINFO_FILENAME));
        $baseName = $this->normalizeBaseName($rawName);
        $extension = $this->resolveLockedExtension($photo);

        $maxBaseLength = max(1, 255 - strlen($extension) - 1);
        if (strlen($baseName) > $maxBaseLength) {
            $baseName = substr($baseName, 0, $maxBaseLength);
        }

        return "{$baseName}.{$extension}";
    }

    private function normalizeBaseName(string $rawName): string
    {
        if ($rawName === '') {
            return 'file';
        }

        if (preg_match('/^[A-Za-z0-9]+$/', $rawName) === 1) {
            return $rawName;
        }

        $ascii = Str::ascii($rawName);
        $words = preg_replace('/[^A-Za-z0-9]+/', ' ', $ascii) ?? '';
        $camel = Str::camel(trim($words));

        return $camel !== '' ? $camel : 'file';
    }

    private function resolveLockedExtension(Photo $photo): string
    {
        $fromOriginal = strtolower(pathinfo((string) $photo->original_name, PATHINFO_EXTENSION));
        if ($fromOriginal !== '') {
            return $fromOriginal;
        }

        $fromPath = strtolower(pathinfo((string) $photo->path, PATHINFO_EXTENSION));

        return $fromPath !== '' ? $fromPath : 'jpg';
    }
}
