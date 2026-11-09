<?php

use App\Http\Controllers\AI\AiStudioController;
use App\Http\Controllers\AI\BulkGenerationController;
use App\Http\Controllers\AI\ContentReviewController;
use App\Http\Controllers\AI\GenerationController;
use App\Http\Controllers\AI\GenerationHistoryController;
use App\Http\Controllers\AI\ProductAnalysisController;
use App\Http\Controllers\Catalog\ExportController;
use App\Http\Controllers\Catalog\ImportController;
use App\Http\Controllers\Catalog\TransferHistoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Integrations\WooCommerceDemoController;
use App\Http\Controllers\Integrations\WooCommerceImportController;
use App\Http\Controllers\Integrations\WooCommerceProductController;
use App\Http\Controllers\Integrations\WooCommerceStoreController;
use App\Http\Controllers\Integrations\WooCommerceSyncController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('products', ProductController::class);

    Route::get('ai-studio', AiStudioController::class)->name('ai-studio');
    Route::get('generation-history', GenerationHistoryController::class)->name('generation-history');

    Route::prefix('ai')->name('ai.')->group(function () {
        Route::post('products/analyze', [ProductAnalysisController::class, 'store'])
            ->middleware('throttle:ai-generate')
            ->name('products.analyze');
        Route::post('products/{product}/generate', [GenerationController::class, 'store'])
            ->middleware('throttle:ai-generate')
            ->name('generate');
        Route::post('generations/{generation}/review', [GenerationController::class, 'review'])
            ->middleware('throttle:120,1')
            ->name('generations.review');
        Route::post('generations/{generation}/save', [GenerationController::class, 'save'])
            ->middleware('throttle:30,1')
            ->name('generations.save');
    });

    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('imports/create', [ImportController::class, 'create'])->name('imports.create');
        Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:20,1')->name('imports.store');
        Route::get('imports/{transfer}', [ImportController::class, 'show'])->name('imports.show');
        Route::get('imports/{transfer}/mapping', [ImportController::class, 'mapping'])->name('imports.mapping');
        Route::post('imports/{transfer}/mapping', [ImportController::class, 'saveMapping'])->name('imports.mapping.save');
        Route::get('imports/{transfer}/preview', [ImportController::class, 'preview'])->name('imports.preview');
        Route::post('imports/{transfer}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
        Route::post('imports/{transfer}/retry', [ImportController::class, 'retry'])->name('imports.retry');
        Route::get('imports/{transfer}/errors', [ImportController::class, 'errors'])->name('imports.errors');

        Route::get('exports/create', [ExportController::class, 'create'])->name('exports.create');
        Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:20,1')->name('exports.store');
        Route::get('exports/{transfer}/download', [ExportController::class, 'download'])->name('exports.download');

        Route::get('history', TransferHistoryController::class)->name('history');
    });

    Route::prefix('ai')->name('ai.')->group(function () {
        Route::get('bulk-generations/create', [BulkGenerationController::class, 'create'])->name('bulk.create');
        Route::post('bulk-generations', [BulkGenerationController::class, 'store'])->middleware('throttle:10,1')->name('bulk.store');
        Route::post('bulk-generations/{batch}/retry', [BulkGenerationController::class, 'retry'])->middleware('throttle:10,1')->name('bulk.retry');

        Route::get('reviews', [ContentReviewController::class, 'index'])->name('reviews.index');
        Route::post('reviews/approve', [ContentReviewController::class, 'bulkApprove'])->name('reviews.approve');
        Route::post('reviews/{item}/reject', [ContentReviewController::class, 'reject'])->name('reviews.reject');
        Route::get('reviews/{item}', [ContentReviewController::class, 'edit'])->name('reviews.edit');
        Route::put('reviews/{item}', [ContentReviewController::class, 'update'])->name('reviews.update');
    });

    Route::prefix('integrations')->name('integrations.')->group(function () {
        Route::get('/', [WooCommerceStoreController::class, 'index'])->name('index');
        Route::post('woocommerce/demo', [WooCommerceDemoController::class, 'activate'])->middleware('throttle:10,1')->name('demo.activate');
        Route::post('woocommerce/demo/sync-items/{item}/simulate-change', [WooCommerceDemoController::class, 'simulateChange'])->middleware('throttle:30,1')->name('demo.simulate-change');
        Route::get('woocommerce/demo/{store}', [WooCommerceDemoController::class, 'show'])->name('demo.show');
        Route::post('woocommerce/demo/{store}/test', [WooCommerceDemoController::class, 'test'])->middleware('throttle:30,1')->name('demo.test');
        Route::post('woocommerce/demo/{store}/outage', [WooCommerceDemoController::class, 'outage'])->middleware('throttle:30,1')->name('demo.outage');
        Route::post('woocommerce/demo/{store}/reset', [WooCommerceDemoController::class, 'reset'])->middleware('throttle:10,1')->name('demo.reset');
        Route::post('woocommerce/demo/{store}/sample-content', [WooCommerceDemoController::class, 'sampleContent'])->middleware('throttle:30,1')->name('demo.sample');
        Route::get('woocommerce/demo/{store}/products/{remoteId}', [WooCommerceDemoController::class, 'product'])->whereNumber('remoteId')->name('demo.product');
        Route::post('woocommerce/demo/{store}/products/{remoteId}/real-content', [WooCommerceDemoController::class, 'realContent'])->whereNumber('remoteId')->name('demo.real');

        Route::get('woocommerce/connect', [WooCommerceStoreController::class, 'create'])->name('create');
        Route::post('woocommerce', [WooCommerceStoreController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::get('woocommerce/sync-history', [WooCommerceSyncController::class, 'history'])->name('sync.history');

        Route::get('woocommerce/stores/{store}/edit', [WooCommerceStoreController::class, 'edit'])->name('edit');
        Route::put('woocommerce/stores/{store}', [WooCommerceStoreController::class, 'update'])->middleware('throttle:10,1')->name('update');
        Route::post('woocommerce/stores/{store}/test', [WooCommerceStoreController::class, 'test'])->middleware('throttle:10,1')->name('test');
        Route::post('woocommerce/stores/{store}/disconnect', [WooCommerceStoreController::class, 'disconnect'])->name('disconnect');
        Route::get('woocommerce/stores/{store}/products', [WooCommerceProductController::class, 'index'])->name('products');

        Route::get('woocommerce/stores/{store}/imports/create', [WooCommerceImportController::class, 'create'])->name('imports.create');
        Route::post('woocommerce/stores/{store}/imports', [WooCommerceImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
        Route::get('woocommerce/imports/{transfer}', [WooCommerceImportController::class, 'show'])->name('imports.show');
        Route::post('woocommerce/imports/{transfer}/confirm', [WooCommerceImportController::class, 'confirm'])->name('imports.confirm');
        Route::post('woocommerce/imports/{transfer}/retry', [WooCommerceImportController::class, 'retry'])->name('imports.retry');

        Route::get('woocommerce/stores/{store}/sync/create', [WooCommerceSyncController::class, 'create'])->name('sync.create');
        Route::post('woocommerce/stores/{store}/sync', [WooCommerceSyncController::class, 'store'])->middleware('throttle:20,1')->name('sync.store');
        Route::get('woocommerce/sync/{run}', [WooCommerceSyncController::class, 'show'])->name('sync.show');
        Route::post('woocommerce/sync/{run}/confirm', [WooCommerceSyncController::class, 'confirm'])->middleware('throttle:20,1')->name('sync.confirm');
        Route::post('woocommerce/sync/{run}/retry', [WooCommerceSyncController::class, 'retry'])->middleware('throttle:20,1')->name('sync.retry');
        Route::post('woocommerce/sync/{run}/refresh', [WooCommerceSyncController::class, 'refresh'])->middleware('throttle:20,1')->name('sync.refresh');
        Route::post('woocommerce/sync/{run}/rollback', [WooCommerceSyncController::class, 'rollback'])->middleware('throttle:20,1')->name('sync.rollback');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('edit');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('password', [PasswordController::class, 'update'])->name('password.update');
    });
});

require __DIR__.'/auth.php';
