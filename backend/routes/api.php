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
        Route::post('/previews/{identifier}/result/finalize', [DraftPreviewPublisherController::class, 'finalizeResult'])->where('identifier', '[a-f0-9]{32}');
    });

Route::prefix('publisher/v1')
    ->middleware(['publisher.auth', 'throttle:publisher-preview-upload'])
    ->group(function (): void {
        Route::post('/previews/{identifier}/result/chunks/{index}', [DraftPreviewPublisherController::class, 'resultChunk'])
            ->where(['identifier' => '[a-f0-9]{32}', 'index' => '(?:0|[1-9][0-9]{0,5})']);
    });
