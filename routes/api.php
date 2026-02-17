<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SeriesController;
use App\Http\Controllers\Api\SeriesPhotoController;
use App\Http\Controllers\Api\TagController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);
        Route::get('auth/me', [ProfileController::class, 'show']);
        Route::patch('auth/me', [ProfileController::class, 'update']);

        Route::apiResource('series', SeriesController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::post('series/{series}/tags', [SeriesController::class, 'attachTags']);
        Route::delete('series/{series}/tags/{tag}', [SeriesController::class, 'detachTag']);

        Route::get('series/{series}/photos', [SeriesPhotoController::class, 'index']);
        Route::post('series/{series}/photos', [SeriesPhotoController::class, 'store']);
        Route::post('series/{series}/photos/retag', [SeriesPhotoController::class, 'retag']);
        Route::patch('series/{series}/photos/reorder', [SeriesPhotoController::class, 'reorder']);
        Route::get('series/{series}/photos/{photo}/download', [SeriesPhotoController::class, 'download']);
        Route::get('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'show']);
        Route::patch('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'update']);
        Route::delete('series/{series}/photos/{photo}', [SeriesPhotoController::class, 'destroy']);

        Route::get('tags', [TagController::class, 'index']);
        Route::post('tags', [TagController::class, 'store']);
        Route::get('tags/suggest', [TagController::class, 'suggest']);
    });
});
