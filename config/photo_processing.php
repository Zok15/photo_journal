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
    'exif_enabled' => (bool) env('PHOTO_EXIF_ENABLED', true),
    // Max number of photos returned in series card preview on index endpoint.
    'series_preview_photos_limit' => max(1, (int) env('PHOTO_SERIES_PREVIEW_LIMIT', 18)),
];
