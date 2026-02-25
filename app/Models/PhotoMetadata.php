<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhotoMetadata extends Model
{
    protected $table = 'photo_metadata';

    protected $fillable = [
        'photo_id',
        'taken_at',
        'camera_make',
        'camera_model',
        'lens_model',
        'iso',
        'exposure_time',
        'aperture',
        'focal_length_mm',
        'width',
        'height',
        'orientation',
        'latitude',
        'longitude',
        'altitude_m',
        'flash_fired',
        'white_balance_mode',
        'color_space',
        'source_file_size',
        'raw_exif_json',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'iso' => 'integer',
            'aperture' => 'decimal:2',
            'focal_length_mm' => 'decimal:2',
            'width' => 'integer',
            'height' => 'integer',
            'orientation' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'altitude_m' => 'decimal:2',
            'flash_fired' => 'boolean',
            'source_file_size' => 'integer',
            'raw_exif_json' => 'array',
        ];
    }

    public function photo()
    {
        return $this->belongsTo(Photo::class);
    }
}
