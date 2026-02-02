<?php

use App\Http\Controllers\Api\SeriesController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/series', [SeriesController::class, 'store']);
});