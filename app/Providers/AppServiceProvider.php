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
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CatalogExportFormat::class, GenericExportFormat::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        // The webhook route is defined in routes/web.php so it can refuse to run without a secret.
        Cashier::ignoreRoutes();
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

        foreach (['api-general' => 'general', 'api-generate' => 'generate', 'api-analyze' => 'analyze', 'api-exports' => 'exports'] as $name => $setting) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute((int) config("api.rate_limits.{$setting}"))
                ->by($request->user()->id)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many requests. Please slow down.',
                    'code' => 'rate_limited',
                    'retry_after' => isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null,
                ], 429, $headers)));
        }

        RateLimiter::for('api-token-creation', fn (Request $request) => Limit::perMinute((int) config('api.rate_limits.token_creation'))->by($request->user()->id));
    }
}
