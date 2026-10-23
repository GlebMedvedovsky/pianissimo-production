<?php

use App\Http\Controllers\ProductionPublisherController;
use App\Http\Controllers\ProductionContentSyncController;
use App\Http\Controllers\DraftPreviewPublisherController;
use App\Http\Controllers\ContactController;
use Illuminate\Support\Facades\Route;

Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact');

Route::prefix('content-sync/v1')
    ->middleware(['content-sync.auth', 'throttle:content-sync'])
    ->group(function (): void {
        Route::get('/production/active', [ProductionContentSyncController::class, 'active'])
            ->name('content-sync.active');
        Route::get('/publications/{publicationId}/publication.json', [ProductionContentSyncController::class, 'publication'])
            ->where('publicationId', '[0-9a-f-]{36}')
            ->name('content-sync.publication');
        Route::get('/publications/{publicationId}/manifest.json', [ProductionContentSyncController::class, 'manifest'])
            ->where('publicationId', '[0-9a-f-]{36}')
            ->name('content-sync.manifest');
        Route::get('/publications/{publicationId}/media/{sha256}', [ProductionContentSyncController::class, 'media'])
            ->where(['publicationId' => '[0-9a-f-]{36}', 'sha256' => '[0-9a-f]{64}'])
            ->name('content-sync.media');
    });

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
