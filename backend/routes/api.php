<?php

use App\Http\Controllers\ProductionPublisherController;
use App\Http\Controllers\DraftPreviewPublisherController;
use Illuminate\Support\Facades\Route;

Route::prefix('publisher/v1')
    ->middleware(['publisher.auth', 'throttle:publisher'])
    ->group(function (): void {
        Route::post('/publications/{publicationId}/claim', [ProductionPublisherController::class, 'claim']);
        Route::get('/publications/{publicationId}/package', [ProductionPublisherController::class, 'package']);
        Route::post('/publications/{publicationId}/status', [ProductionPublisherController::class, 'status']);
        Route::get('/previews/{identifier}/package', [DraftPreviewPublisherController::class, 'package'])->where('identifier', '[a-f0-9]{32}');
        Route::post('/previews/{identifier}/result', [DraftPreviewPublisherController::class, 'result'])->where('identifier', '[a-f0-9]{32}');
    });
