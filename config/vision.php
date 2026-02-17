<?php

return [
    'enabled' => env('VISION_TAGGER_ENABLED', false),
    'url' => env('VISION_TAGGER_URL', 'http://127.0.0.1:8010/tag'),
    'timeout_seconds' => (int) env('VISION_TAGGER_TIMEOUT', 10),
    'max_tags' => (int) env('VISION_TAGGER_MAX_TAGS', 8),
    'max_hints' => (int) env('VISION_TAGGER_MAX_HINTS', 20),
    'min_confidence' => (float) env('VISION_TAGGER_MIN_CONFIDENCE', 0.22),
    'skip_if_tags_count_at_least' => (int) env('VISION_SKIP_IF_TAGS_COUNT_AT_LEAST', 7),
];
