<?php

return [
    // Feature flags for incremental processing pipeline rollout.
    'preview_enabled' => (bool) env('PHOTO_PREVIEW_ENABLED', true),
    // If enabled, preview_url uses temporary signed links (when driver supports it).
    'preview_signed_urls' => (bool) env('PHOTO_PREVIEW_SIGNED_URLS', false),
    'preview_signed_ttl_minutes' => max(1, (int) env('PHOTO_PREVIEW_SIGNED_TTL_MINUTES', 30)),
    // Generated preview variant options.
    'preview_max_width' => max(160, (int) env('PHOTO_PREVIEW_MAX_WIDTH', 640)),
    'preview_webp_quality' => max(30, min(100, (int) env('PHOTO_PREVIEW_WEBP_QUALITY', 72))),
    // Uploaded originals are normalized for web delivery and size-capped on backend.
    'upload_max_bytes' => max(256000, (int) env('PHOTO_UPLOAD_MAX_BYTES', 2 * 1024 * 1024)),
    'upload_max_dimension' => max(640, (int) env('PHOTO_UPLOAD_MAX_DIMENSION', 3840)),
    'upload_min_dimension' => max(320, (int) env('PHOTO_UPLOAD_MIN_DIMENSION', 1200)),
    'upload_jpeg_quality_start' => max(40, min(100, (int) env('PHOTO_UPLOAD_JPEG_QUALITY_START', 92))),
    'upload_jpeg_quality_min' => max(25, min(95, (int) env('PHOTO_UPLOAD_JPEG_QUALITY_MIN', 55))),
    'exif_enabled' => (bool) env('PHOTO_EXIF_ENABLED', true),
    // Max number of photos returned in series card preview on index endpoint.
    'series_preview_photos_limit' => max(1, (int) env('PHOTO_SERIES_PREVIEW_LIMIT', 18)),
];
