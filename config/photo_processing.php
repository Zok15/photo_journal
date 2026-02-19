<?php

return [
    // Feature flags for incremental processing pipeline rollout.
    'preview_enabled' => (bool) env('PHOTO_PREVIEW_ENABLED', true),
    // If enabled, preview_url uses temporary signed links (when driver supports it).
    'preview_signed_urls' => (bool) env('PHOTO_PREVIEW_SIGNED_URLS', false),
    'preview_signed_ttl_minutes' => max(1, (int) env('PHOTO_PREVIEW_SIGNED_TTL_MINUTES', 30)),
    'exif_enabled' => (bool) env('PHOTO_EXIF_ENABLED', true),
    // Max number of photos returned in series card preview on index endpoint.
    'series_preview_photos_limit' => max(1, (int) env('PHOTO_SERIES_PREVIEW_LIMIT', 18)),
];
