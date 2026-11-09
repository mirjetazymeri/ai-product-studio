<?php

namespace App\Providers;

use App\Enums\AiError;
use App\Services\Catalog\Export\CatalogExportFormat;
use App\Services\Catalog\Export\GenericExportFormat;
use App\Services\WooCommerce\Http\DnsHostResolver;
use App\Services\WooCommerce\Http\HostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CatalogExportFormat::class, GenericExportFormat::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('ai-generate', function (Request $request) {
            return Limit::perMinute((int) config('services.gemini.per_minute_limit'))
                ->by($request->user()->id)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => AiError::RateLimited->message(),
                    'code' => AiError::RateLimited->value,
                    'retry_after' => isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null,
                ], 429, $headers));
        });
    }
}
