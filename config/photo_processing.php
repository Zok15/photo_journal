<?php

return [
    // Feature flags for incremental processing pipeline rollout.
    'preview_enabled' => (bool) env('PHOTO_PREVIEW_ENABLED', true),
    'exif_enabled' => (bool) env('PHOTO_EXIF_ENABLED', true),
];
