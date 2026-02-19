<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Outbox Retry Policy
    |--------------------------------------------------------------------------
    |
    | max_attempts:
    |   How many delivery attempts are allowed for one outbox event.
    |   After this limit is reached, the event is marked as "failed"
    |   and is no longer picked by outbox:poll automatically.
    |
    | base_backoff_seconds:
    |   Base delay for exponential retry strategy.
    |   Backoff formula is: base * 2^(attempt - 1).
    |
    | max_backoff_seconds:
    |   Upper cap for retry delay to avoid unbounded waits.
    |
    | processing_stale_seconds:
    |   If an event stays in "processing" longer than this threshold,
    |   outbox:poll treats it as stale and recovers it:
    |   - back to "pending" when attempts < max_attempts
    |   - to "failed" when attempts >= max_attempts
    |
    */
    'retry' => [
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 5),
        'base_backoff_seconds' => (int) env('OUTBOX_BACKOFF_BASE_SECONDS', 60),
        'max_backoff_seconds' => (int) env('OUTBOX_BACKOFF_MAX_SECONDS', 3600),
        'processing_stale_seconds' => (int) env('OUTBOX_PROCESSING_STALE_SECONDS', 900),
    ],
];
