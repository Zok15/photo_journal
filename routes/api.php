<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SeriesController;
use App\Http\Controllers\Api\SeriesPhotoController;
use App\Http\Controllers\Api\TagController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);

        Route::apiResource('series', SeriesController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        Route::get('series/{series}/photos', [SeriesPhotoController::class, 'index']);
        Route::post('series/{series}/photos', [SeriesPhotoController::class, 'store']);
        Route::post('series/{series}/photos/retag', [SeriesPhotoController::class, 'retag']);
        Route::patch('series/{series}/photos/reorder', [SeriesPhotoController::class, 'reorder']);
        Route::get('series/{series}/photos/{photo}/download', [SeriesPhotoController::class, 'download']);
        Route::get('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'show']);
        Route::patch('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'update']);
        Route::delete('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'destroy']);

        Route::post('tags', [TagController::class, 'store']);
        Route::patch('tags/{tag}', [TagController::class, 'update']);
    });
});
