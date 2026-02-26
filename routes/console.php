<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:poll')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('tags:cleanup-garbage-auto')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('seo:build-showcase')
    ->dailyAt('03:15')
    ->withoutOverlapping();
