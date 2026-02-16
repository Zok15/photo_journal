<?php

use App\Http\Controllers\Api\SeriesController;
use App\Http\Controllers\Api\SeriesPhotoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::apiResource('series', SeriesController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::get('series/{series}/photos', [SeriesPhotoController::class, 'index']);
    Route::post('series/{series}/photos', [SeriesPhotoController::class, 'store']);
    Route::get('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'show']);
    Route::patch('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'update']);
    Route::delete('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'destroy']);
    Route::put('series/{series}/photos/{photo}/tags', [SeriesPhotoController::class, 'syncTags']);
    Route::post('series/{series}/photos/{photo}/tags', [SeriesPhotoController::class, 'attachTags']);
    Route::delete('series/{series}/photos/{photo}/tags/{tag}', [SeriesPhotoController::class, 'detachTag']);
});
