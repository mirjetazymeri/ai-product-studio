<?php

use App\Http\Controllers\AI\AiStudioController;
use App\Http\Controllers\AI\BulkGenerationController;
use App\Http\Controllers\AI\ContentReviewController;
use App\Http\Controllers\AI\GenerationController;
use App\Http\Controllers\AI\GenerationHistoryController;
use App\Http\Controllers\AI\ProductAnalysisController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\DemoBillingController;
use App\Http\Controllers\Billing\PricingController;
use App\Http\Controllers\Billing\StripeWebhookController;
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
use App\Http\Controllers\Settings\ApiDocumentationController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Middleware\EnsureStripeWebhooksEnabled;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::get('pricing', PricingController::class)->name('pricing');

// The name matches Cashier's, so its cashier:webhook command finds this route.
Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->middleware(EnsureStripeWebhooksEnabled::class)
    ->name('cashier.webhook');

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

    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/', [BillingController::class, 'index'])->name('index');
        Route::get('plans/{plan}', [BillingController::class, 'confirm'])->name('plans.confirm');
        Route::post('plans/{plan}', [BillingController::class, 'change'])->middleware('throttle:20,1')->name('plans.change');
        Route::post('cancel', [BillingController::class, 'cancel'])->middleware('throttle:20,1')->name('cancel');
        Route::post('resume', [BillingController::class, 'resume'])->middleware('throttle:20,1')->name('resume');
        Route::get('portal', [BillingController::class, 'portal'])->middleware('throttle:20,1')->name('portal');
        Route::get('records/{record}', [BillingController::class, 'record'])->name('records.show');
        Route::get('checkout/return', [BillingController::class, 'checkoutReturn'])->name('checkout.return');

        Route::prefix('demo')->name('demo.')->middleware('throttle:60,1')->group(function () {
            Route::post('payment-failure', [DemoBillingController::class, 'paymentFailure'])->name('payment-failure');
            Route::post('payment-recovery', [DemoBillingController::class, 'paymentRecovery'])->name('payment-recovery');
            Route::post('expire', [DemoBillingController::class, 'expire'])->name('expire');
            Route::post('restore-free', [DemoBillingController::class, 'restoreFree'])->name('restore-free');
            Route::post('use-allowance', [DemoBillingController::class, 'useAllowance'])->name('use-allowance');
            Route::post('reset', [DemoBillingController::class, 'reset'])->name('reset');
        });
    });

    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('imports/create', [ImportController::class, 'create'])->middleware('plan:catalog.import')->name('imports.create');
        Route::post('imports', [ImportController::class, 'store'])->middleware(['throttle:20,1', 'plan:catalog.import'])->name('imports.store');
        Route::get('imports/{transfer}', [ImportController::class, 'show'])->name('imports.show');
        Route::get('imports/{transfer}/mapping', [ImportController::class, 'mapping'])->name('imports.mapping');
        Route::post('imports/{transfer}/mapping', [ImportController::class, 'saveMapping'])->name('imports.mapping.save');
        Route::get('imports/{transfer}/preview', [ImportController::class, 'preview'])->name('imports.preview');
        Route::post('imports/{transfer}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
        Route::post('imports/{transfer}/retry', [ImportController::class, 'retry'])->name('imports.retry');
        Route::get('imports/{transfer}/errors', [ImportController::class, 'errors'])->name('imports.errors');

        Route::get('exports/create', [ExportController::class, 'create'])->middleware('plan:catalog.export')->name('exports.create');
        Route::post('exports', [ExportController::class, 'store'])->middleware(['throttle:20,1', 'plan:catalog.export'])->name('exports.store');
        Route::get('exports/{transfer}/download', [ExportController::class, 'download'])->name('exports.download');

        Route::get('history', TransferHistoryController::class)->name('history');
    });

    Route::prefix('ai')->name('ai.')->group(function () {
        Route::get('bulk-generations/create', [BulkGenerationController::class, 'create'])->middleware('plan:ai.bulk')->name('bulk.create');
        Route::post('bulk-generations', [BulkGenerationController::class, 'store'])->middleware(['throttle:10,1', 'plan:ai.bulk'])->name('bulk.store');
        Route::post('bulk-generations/{batch}/retry', [BulkGenerationController::class, 'retry'])->middleware(['throttle:10,1', 'plan:ai.bulk'])->name('bulk.retry');

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

        Route::get('woocommerce/connect', [WooCommerceStoreController::class, 'create'])->middleware('plan.store')->name('create');
        Route::post('woocommerce', [WooCommerceStoreController::class, 'store'])->middleware(['throttle:10,1', 'plan.store'])->name('store');
        Route::get('woocommerce/sync-history', [WooCommerceSyncController::class, 'history'])->name('sync.history');

        Route::get('woocommerce/stores/{store}/edit', [WooCommerceStoreController::class, 'edit'])->name('edit');
        Route::put('woocommerce/stores/{store}', [WooCommerceStoreController::class, 'update'])->middleware(['throttle:10,1', 'plan.store'])->name('update');
        Route::post('woocommerce/stores/{store}/test', [WooCommerceStoreController::class, 'test'])->middleware(['throttle:10,1', 'plan.store'])->name('test');
        Route::post('woocommerce/stores/{store}/disconnect', [WooCommerceStoreController::class, 'disconnect'])->name('disconnect');
        Route::get('woocommerce/stores/{store}/products', [WooCommerceProductController::class, 'index'])->name('products');

        Route::get('woocommerce/stores/{store}/imports/create', [WooCommerceImportController::class, 'create'])->middleware('plan.store')->name('imports.create');
        Route::post('woocommerce/stores/{store}/imports', [WooCommerceImportController::class, 'store'])->middleware(['throttle:10,1', 'plan.store'])->name('imports.store');
        Route::get('woocommerce/imports/{transfer}', [WooCommerceImportController::class, 'show'])->name('imports.show');
        Route::post('woocommerce/imports/{transfer}/confirm', [WooCommerceImportController::class, 'confirm'])->middleware('plan.store')->name('imports.confirm');
        Route::post('woocommerce/imports/{transfer}/retry', [WooCommerceImportController::class, 'retry'])->middleware('plan.store')->name('imports.retry');

        Route::get('woocommerce/stores/{store}/sync/create', [WooCommerceSyncController::class, 'create'])->middleware('plan.store')->name('sync.create');
        Route::post('woocommerce/stores/{store}/sync', [WooCommerceSyncController::class, 'store'])->middleware(['throttle:20,1', 'plan.store'])->name('sync.store');
        Route::get('woocommerce/sync/{run}', [WooCommerceSyncController::class, 'show'])->name('sync.show');
        Route::post('woocommerce/sync/{run}/confirm', [WooCommerceSyncController::class, 'confirm'])->middleware(['throttle:20,1', 'plan.store'])->name('sync.confirm');
        Route::post('woocommerce/sync/{run}/retry', [WooCommerceSyncController::class, 'retry'])->middleware(['throttle:20,1', 'plan.store'])->name('sync.retry');
        Route::post('woocommerce/sync/{run}/refresh', [WooCommerceSyncController::class, 'refresh'])->middleware(['throttle:20,1', 'plan.store'])->name('sync.refresh');
        Route::post('woocommerce/sync/{run}/rollback', [WooCommerceSyncController::class, 'rollback'])->middleware(['throttle:20,1', 'plan.store'])->name('sync.rollback');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('edit');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('password', [PasswordController::class, 'update'])->name('password.update');

        Route::post('api-tokens', [ApiTokenController::class, 'store'])->middleware(['throttle:api-token-creation', 'plan:api.access'])->name('api-tokens.store');
        Route::delete('api-tokens/{token}', [ApiTokenController::class, 'destroy'])->whereNumber('token')->name('api-tokens.destroy');

        Route::get('api-docs/openapi.yaml', [ApiDocumentationController::class, 'openApi'])->name('api-docs.openapi');
        Route::get('api-docs/{page}', [ApiDocumentationController::class, 'show'])->name('api-docs');
    });
});

require __DIR__.'/auth.php';
