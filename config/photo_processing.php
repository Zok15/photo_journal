<?php

return [
    // Feature flags for incremental processing pipeline rollout.
    'preview_enabled' => (bool) env('PHOTO_PREVIEW_ENABLED', true),
    'exif_enabled' => (bool) env('PHOTO_EXIF_ENABLED', true),
    // Max number of photos returned in series card preview on index endpoint.
    'series_preview_photos_limit' => max(1, (int) env('PHOTO_SERIES_PREVIEW_LIMIT', 18)),
];
