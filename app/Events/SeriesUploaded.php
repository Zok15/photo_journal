<?php

namespace App\Events;

use App\Models\Series;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeriesUploaded
{
    use Dispatchable, SerializesModels;

    public function __construct(public Series $series) {}
}