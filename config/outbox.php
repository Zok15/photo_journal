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
    */
    'retry' => [
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 5),
        'base_backoff_seconds' => (int) env('OUTBOX_BACKOFF_BASE_SECONDS', 60),
        'max_backoff_seconds' => (int) env('OUTBOX_BACKOFF_MAX_SECONDS', 3600),
    ],
];
