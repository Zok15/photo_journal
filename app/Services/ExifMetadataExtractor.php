<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ExifMetadataExtractor
{
    /**
     * @return array<string, mixed>|null
     */
    public function extractFromUploadedFile(UploadedFile $file): ?array
    {
        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
            return null;
        }

        return $this->extractFromAbsolutePath($realPath);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extractFromStoragePath(string $disk, string $path): ?array
    {
        $localPath = $this->resolveLocalPathFromDisk($disk, $path);
        if ($localPath !== null) {
            return $this->extractFromAbsolutePath($localPath);
        }

        return $this->extractFromStreamedDiskFile($disk, $path);
    }

    private function resolveLocalPathFromDisk(string $disk, string $path): ?string
    {
        try {
            $absolutePath = Storage::disk($disk)->path($path);
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($absolutePath) || $absolutePath === '' || !is_file($absolutePath)) {
            return null;
        }

        return $absolutePath;
    }

    /**
     * For remote disks (s3 etc.) copy file to a temporary local path for EXIF parsing.
     */
    private function extractFromStreamedDiskFile(string $disk, string $path): ?array
    {
        $stream = null;
        $tempPath = null;

        try {
            $stream = Storage::disk($disk)->readStream($path);
            if (!is_resource($stream)) {
                return null;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'pj-exif-');
            if (!is_string($tempPath) || $tempPath === '') {
                return null;
            }

            $tempStream = fopen($tempPath, 'wb');
            if (!is_resource($tempStream)) {
                return null;
            }

            try {
                stream_copy_to_stream($stream, $tempStream);
            } finally {
                fclose($tempStream);
            }

            return $this->extractFromAbsolutePath($tempPath);
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (is_string($tempPath) && $tempPath !== '' && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $rawExif
     * @return array<string, mixed>|null
     */
    public function normalizeFromArray(array $rawExif): ?array
    {
        if ($rawExif === []) {
            return null;
        }

        $takenAt = $this->parseTakenAt(
            $this->firstRawValue($rawExif, ['EXIF.DateTimeOriginal', 'EXIF.CreateDate', 'EXIF.DateTimeDigitized', 'IFD0.DateTime'])
        );
        $cameraMake = $this->cleanString($this->firstRawValue($rawExif, ['IFD0.Make', 'Make']));
        $cameraModel = $this->cleanString($this->firstRawValue($rawExif, ['IFD0.Model', 'Model']));
        $lensModel = $this->cleanString($this->firstRawValue($rawExif, ['EXIF.LensModel', 'COMPOSITE.LensModel', 'LensModel']));
        $iso = $this->parsePositiveInt($this->firstRawValue($rawExif, ['EXIF.ISOSpeedRatings', 'EXIF.PhotographicSensitivity', 'ISOSpeedRatings']));
        $exposureTime = $this->parseExposureTime($this->firstRawValue($rawExif, ['EXIF.ExposureTime', 'ExposureTime']));
        $aperture = $this->parsePositiveFloat($this->firstRawValue($rawExif, ['EXIF.FNumber', 'COMPOSITE.Aperture', 'FNumber']), 2);
        $focalLength = $this->parsePositiveFloat($this->firstRawValue($rawExif, ['EXIF.FocalLength', 'COMPOSITE.FocalLength', 'FocalLength']), 2);
        $width = $this->parsePositiveInt($this->firstRawValue($rawExif, ['EXIF.ExifImageWidth', 'COMPUTED.Width', 'ExifImageWidth']));
        $height = $this->parsePositiveInt($this->firstRawValue($rawExif, ['EXIF.ExifImageLength', 'COMPUTED.Height', 'ExifImageLength']));
        $orientation = $this->parsePositiveInt($this->firstRawValue($rawExif, ['IFD0.Orientation', 'Orientation']));

        [$latitude, $longitude] = $this->parseGpsLatLon($rawExif);
        $altitude = $this->parseGpsAltitude($rawExif);
        $flashFired = $this->parseFlashFired($this->firstRawValue($rawExif, ['EXIF.Flash', 'Flash']));
        $whiteBalanceMode = $this->parseWhiteBalanceMode($this->firstRawValue($rawExif, ['EXIF.WhiteBalance', 'WhiteBalance']));
        $colorSpace = $this->parseColorSpace($this->firstRawValue($rawExif, ['EXIF.ColorSpace', 'ColorSpace']));
        $sourceFileSize = $this->parsePositiveInt($this->firstRawValue($rawExif, ['FILE.FileSize', 'FileSize']));
        $sanitizedRaw = $this->sanitizeRawExif($rawExif);

        $normalized = array_filter([
            'taken_at' => $takenAt,
            'camera_make' => $cameraMake,
            'camera_model' => $cameraModel,
            'lens_model' => $lensModel,
            'iso' => $iso,
            'exposure_time' => $exposureTime,
            'aperture' => $aperture,
            'focal_length_mm' => $focalLength,
            'width' => $width,
            'height' => $height,
            'orientation' => $orientation,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude_m' => $altitude,
            'flash_fired' => $flashFired,
            'white_balance_mode' => $whiteBalanceMode,
            'color_space' => $colorSpace,
            'source_file_size' => $sourceFileSize,
        ], static fn ($value): bool => $value !== null);

        if ($normalized === []) {
            return null;
        }

        $normalized['raw_exif_json'] = $sanitizedRaw;

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractFromAbsolutePath(string $path): ?array
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        /** @var mixed $rawExif */
        $rawExif = @exif_read_data($path, null, true, false);
        if (!is_array($rawExif) || $rawExif === []) {
            return null;
        }

        return $this->normalizeFromArray($rawExif);
    }

    /**
     * @param array<string, mixed> $rawExif
     * @param array<int, string> $paths
     */
    private function firstRawValue(array $rawExif, array $paths): mixed
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            if (count($segments) === 2) {
                $section = $segments[0];
                $key = $segments[1];
                $sectionData = $rawExif[$section] ?? null;
                if (is_array($sectionData) && array_key_exists($key, $sectionData)) {
                    return $sectionData[$key];
                }
            }

            if (array_key_exists($path, $rawExif)) {
                return $rawExif[$path];
            }

            foreach ($rawExif as $sectionData) {
                if (!is_array($sectionData)) {
                    continue;
                }

                if (array_key_exists($path, $sectionData)) {
                    return $sectionData[$path];
                }
            }
        }

        return null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null) {
            return null;
        }

        $result = trim((string) $value);
        return $result === '' ? null : $result;
    }

    private function parseTakenAt(mixed $value): ?string
    {
        $source = $this->cleanString($value);
        if ($source === null) {
            return null;
        }

        foreach (['Y:m:d H:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $source);
                return $parsed->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($source)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        $parsed = null;
        if (is_numeric($value)) {
            $parsed = (int) $value;
        } else {
            $float = $this->parseRationalToFloat((string) $value);
            if ($float !== null) {
                $parsed = (int) round($float);
            }
        }

        return $parsed !== null && $parsed > 0 ? $parsed : null;
    }

    private function parsePositiveFloat(mixed $value, int $precision): ?float
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        $parsed = null;
        if (is_numeric($value)) {
            $parsed = (float) $value;
        } else {
            $parsed = $this->parseRationalToFloat((string) $value);
        }

        if ($parsed === null || $parsed <= 0) {
            return null;
        }

        return round($parsed, $precision);
    }

    private function parseExposureTime(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (preg_match('/^\d+\/\d+$/', $string) === 1) {
            return $string;
        }

        $float = is_numeric($string) ? (float) $string : $this->parseRationalToFloat($string);
        if ($float === null || $float <= 0) {
            return $string;
        }

        if ($float >= 1) {
            return rtrim(rtrim(number_format($float, 3, '.', ''), '0'), '.').'s';
        }

        $denominator = (int) round(1 / $float);
        if ($denominator > 0) {
            return '1/'.$denominator;
        }

        return $string;
    }

    private function parseRationalToFloat(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $numerator = (float) $matches[1];
        $denominator = (float) $matches[2];
        if ($denominator == 0.0) {
            return null;
        }

        return $numerator / $denominator;
    }

    /**
     * @param array<string, mixed> $rawExif
     * @return array{0:float|null,1:float|null}
     */
    private function parseGpsLatLon(array $rawExif): array
    {
        $latRaw = $this->firstRawValue($rawExif, ['GPS.GPSLatitude', 'GPSLatitude']);
        $latRef = $this->cleanString($this->firstRawValue($rawExif, ['GPS.GPSLatitudeRef', 'GPSLatitudeRef']));
        $lonRaw = $this->firstRawValue($rawExif, ['GPS.GPSLongitude', 'GPSLongitude']);
        $lonRef = $this->cleanString($this->firstRawValue($rawExif, ['GPS.GPSLongitudeRef', 'GPSLongitudeRef']));

        $latitude = $this->parseGpsCoordinate($latRaw, $latRef, ['N', 'S']);
        $longitude = $this->parseGpsCoordinate($lonRaw, $lonRef, ['E', 'W']);

        return [$latitude, $longitude];
    }

    /**
     * @param array<string, mixed> $rawExif
     */
    private function parseGpsAltitude(array $rawExif): ?float
    {
        $raw = $this->firstRawValue($rawExif, ['GPS.GPSAltitude', 'GPSAltitude']);
        $ref = $this->firstRawValue($rawExif, ['GPS.GPSAltitudeRef', 'GPSAltitudeRef']);

        $value = $this->parsePositiveFloat($raw, 2);
        if ($value === null) {
            return null;
        }

        if ((string) $ref === '1') {
            return round($value * -1, 2);
        }

        return $value;
    }


    private function parseFlashFired(mixed $value): ?bool
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (((int) $value) & 1) === 1;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, 'fired')) {
            return true;
        }

        if (str_contains($raw, 'did not fire') || str_contains($raw, 'no flash')) {
            return false;
        }

        return null;
    }

    private function parseWhiteBalanceMode(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return match ((int) $value) {
                0 => 'auto',
                1 => 'manual',
                default => 'unknown',
            };
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'auto')) {
            return 'auto';
        }

        if (str_contains($normalized, 'manual')) {
            return 'manual';
        }

        return $normalized;
    }

    private function parseColorSpace(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return match ((int) $value) {
                1 => 'sRGB',
                65535 => 'uncalibrated',
                default => 'unknown',
            };
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param array<int, string> $expectedRefs
     */
    private function parseGpsCoordinate(mixed $value, ?string $ref, array $expectedRefs): ?float
    {
        if (!is_array($value) || count($value) < 3) {
            return null;
        }

        $degrees = $this->parseCoordinatePart($value[0] ?? null);
        $minutes = $this->parseCoordinatePart($value[1] ?? null);
        $seconds = $this->parseCoordinatePart($value[2] ?? null);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        $upperRef = strtoupper((string) $ref);
        if (!in_array($upperRef, $expectedRefs, true)) {
            return round($decimal, 7);
        }

        if ($upperRef === 'S' || $upperRef === 'W') {
            $decimal *= -1;
        }

        return round($decimal, 7);
    }

    private function parseCoordinatePart(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $this->parseRationalToFloat((string) $value);
    }

    /**
     * @param array<string, mixed> $rawExif
     * @return array<string, mixed>
     */
    private function sanitizeRawExif(array $rawExif): array
    {
        $sanitize = function (mixed $value) use (&$sanitize) {
            if (is_array($value)) {
                $result = [];
                foreach ($value as $key => $item) {
                    $result[(string) $key] = $sanitize($item);
                }

                return $result;
            }

            if (is_string($value)) {
                return $this->sanitizeUtf8String($value);
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                return $value;
            }

            return $this->sanitizeUtf8String((string) $value);
        };

        /** @var array<string, mixed> $sanitized */
        $sanitized = $sanitize($rawExif);

        return $sanitized;
    }

    private function sanitizeUtf8String(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '' && preg_match('//u', $converted) === 1) {
                return $converted;
            }
        }

        return '[binary:'.base64_encode($value).']';
    }
}
