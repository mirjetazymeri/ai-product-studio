<?php

use App\Enums\ContentLanguage;
use App\Http\Controllers\Api\V1\ApprovedContentController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\ExternalReferenceController;
use App\Http\Controllers\Api\V1\GenerationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProductAnalysisController;
use App\Http\Controllers\Api\V1\ProductContentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SeoAnalysisController;
use App\Http\Middleware\EnsureBearerToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', EnsureBearerToken::class, 'throttle:api-general'])
    ->where([
        'product' => '[0-9]+',
        'generation' => '[0-9]+',
        'export' => '[0-9]+',
        'language' => implode('|', ContentLanguage::values()),
    ])
    ->group(function () {
        // Open on every plan, so a client can see why the other endpoints refuse it.
        Route::get('me', MeController::class)->name('me');

        Route::middleware('plan:api.access')->group(function () {

            Route::middleware('abilities:products:read')->group(function () {
                Route::get('products', [ProductController::class, 'index'])->name('products.index');
                Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
            });

            Route::middleware('abilities:products:write')->group(function () {
                Route::post('products', [ProductController::class, 'store'])->middleware('idempotent')->name('products.store');
                Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
                Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

                Route::put('products/{product}/external-references/{source}', [ExternalReferenceController::class, 'update'])->name('products.external-references.update');
                Route::delete('products/{product}/external-references/{source}', [ExternalReferenceController::class, 'destroy'])->name('products.external-references.destroy');
            });

            Route::middleware('abilities:content:read')->group(function () {
                Route::get('products/{product}/contents', [ProductContentController::class, 'index'])->name('products.contents.index');
                Route::get('products/{product}/contents/{language}', [ProductContentController::class, 'show'])->name('products.contents.show');
                Route::get('products/{product}/approved-content', ApprovedContentController::class)->name('products.approved-content');
                Route::post('seo/analyze', SeoAnalysisController::class)->name('seo.analyze');
            });

            Route::put('products/{product}/contents/{language}', [ProductContentController::class, 'update'])
                ->middleware('abilities:content:write')
                ->name('products.contents.update');

            Route::middleware('abilities:ai:generate')->group(function () {
                // ai-generate is the counter the web forms use, so Gemini's limit is shared.
                Route::post('ai/analyze-product', ProductAnalysisController::class)
                    ->middleware(['idempotent', 'throttle:api-analyze', 'throttle:ai-generate'])
                    ->name('ai.analyze-product');

                Route::post('products/{product}/generate', [GenerationController::class, 'store'])
                    ->middleware(['idempotent', 'throttle:api-generate'])
                    ->name('products.generate');
            });

            Route::get('generations/{generation}', [GenerationController::class, 'show'])
                ->middleware('abilities:ai:read')
                ->name('generations.show');

            Route::middleware('abilities:exports:read')->group(function () {
                Route::post('exports', [ExportController::class, 'store'])->middleware(['idempotent', 'throttle:api-exports'])->name('exports.store');
                Route::get('exports/{export}', [ExportController::class, 'show'])->name('exports.show');
                Route::get('exports/{export}/download', [ExportController::class, 'download'])->name('exports.download');
            });
        });
    });
