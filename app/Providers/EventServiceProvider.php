<?php

namespace App\Providers;

use App\Events\SeriesUploaded;
use App\Listeners\AddSeriesUploadedToOutbox;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        SeriesUploaded::class => [
            AddSeriesUploadedToOutbox::class,
        ],
    ];
}
