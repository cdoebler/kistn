<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\FindingController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PreflightController;
use App\Http\Middleware\EnsureTokenBelongsToProject;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', EnsureTokenBelongsToProject::class])
    ->prefix('projects/{project}')
    ->group(function (): void {
        Route::get('hashes', [InventoryController::class, 'hashes'])
            ->name('api.projects.hashes');

        Route::post('inventory', [InventoryController::class, 'store'])
            ->middleware('throttle:inventory-push')
            ->name('api.projects.inventory');

        Route::post('files', [FileController::class, 'store'])
            ->middleware('throttle:inventory-files')
            ->name('api.projects.files');

        Route::get('packages', [PackageController::class, 'index'])
            ->name('api.projects.packages');

        Route::get('events', [EventController::class, 'index'])
            ->name('api.projects.events');

        Route::get('findings', [FindingController::class, 'index'])
            ->name('api.projects.findings');

        Route::post('preflight/wp', [PreflightController::class, 'wp'])
            ->middleware('throttle:inventory-preflight')
            ->name('api.projects.preflight.wp');
    });
