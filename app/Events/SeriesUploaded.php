<?php

namespace App\Events;

use App\Models\Series;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Доменное событие: серия загружена и прошла первичную обработку.
 */
class SeriesUploaded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Series $series)
    {
    }
}
