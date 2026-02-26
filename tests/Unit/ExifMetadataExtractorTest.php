<?php

namespace Tests\Unit;

use App\Services\ExifMetadataExtractor;
use Tests\TestCase;

class ExifMetadataExtractorTest extends TestCase
{
    public function test_normalize_from_array_maps_core_exif_fields(): void
    {
        $extractor = new ExifMetadataExtractor();

        $metadata = $extractor->normalizeFromArray([
            'IFD0' => [
                'Make' => 'Nikon',
                'Model' => 'Z6 II',
            ],
            'EXIF' => [
                'DateTimeOriginal' => '2026:02:25 10:12:13',
                'CreateDate' => '2026:02:25 10:12:13',
                'LensModel' => 'NIKKOR Z 24-70mm f/4 S',
                'ISOSpeedRatings' => '200',
                'ExposureTime' => '1/250',
                'FNumber' => '56/10',
                'FocalLength' => '50/1',
                'ExifImageWidth' => '6048',
                'ExifImageLength' => '4024',
                'Flash' => 1,
                'WhiteBalance' => 0,
                'ColorSpace' => 1,
            ],
            'GPS' => [
                'GPSLatitudeRef' => 'N',
                'GPSLatitude' => ['55/1', '45/1', '0/1'],
                'GPSLongitudeRef' => 'E',
                'GPSLongitude' => ['37/1', '37/1', '0/1'],
            ],
            'FILE' => [
                'FileSize' => 1234567,
            ],
        ]);

        $this->assertNotNull($metadata);
        $this->assertSame('2026-02-25 10:12:13', $metadata['taken_at']);
        $this->assertSame('Nikon', $metadata['camera_make']);
        $this->assertSame('Z6 II', $metadata['camera_model']);
        $this->assertSame('NIKKOR Z 24-70mm f/4 S', $metadata['lens_model']);
        $this->assertSame(200, $metadata['iso']);
        $this->assertSame('1/250', $metadata['exposure_time']);
        $this->assertSame(5.6, $metadata['aperture']);
        $this->assertSame(50.0, $metadata['focal_length_mm']);
        $this->assertSame(6048, $metadata['width']);
        $this->assertSame(4024, $metadata['height']);
        $this->assertSame(37.6166667, $metadata['longitude']);
        $this->assertSame(55.75, $metadata['latitude']);
        $this->assertSame(true, $metadata['flash_fired']);
        $this->assertSame('auto', $metadata['white_balance_mode']);
        $this->assertSame('sRGB', $metadata['color_space']);
        $this->assertSame(1234567, $metadata['source_file_size']);
        $this->assertIsArray($metadata['raw_exif_json']);
    }

    public function test_normalize_from_array_returns_null_for_empty_payload(): void
    {
        $extractor = new ExifMetadataExtractor();

        $metadata = $extractor->normalizeFromArray([]);

        $this->assertNull($metadata);
    }

    public function test_normalize_from_array_sanitizes_invalid_utf8_in_raw_exif_json(): void
    {
        $extractor = new ExifMetadataExtractor();
        $invalidUtf8 = "\xB1\x31";

        $metadata = $extractor->normalizeFromArray([
            'EXIF' => [
                'DateTimeOriginal' => '2026:02:25 10:12:13',
                'UserComment' => $invalidUtf8,
            ],
        ]);

        $this->assertNotNull($metadata);
        $this->assertSame('2026-02-25 10:12:13', $metadata['taken_at']);
        $this->assertIsArray($metadata['raw_exif_json']);
        $this->assertIsString($metadata['raw_exif_json']['EXIF']['UserComment'] ?? null);
        $this->assertStringStartsWith('[binary:', (string) ($metadata['raw_exif_json']['EXIF']['UserComment'] ?? ''));
    }
}
