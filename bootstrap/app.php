<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsureStorePlan;
use App\Http\Middleware\PrepareApiRequest;
use App\Http\Middleware\ThrottleFailedApiAuthentication;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // In the group itself, so both stay in front of authentication after Laravel
        // sorts route middleware by priority.
        $middleware->api(prepend: [
            PrepareApiRequest::class,
            ThrottleFailedApiAuthentication::class,
        ]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'idempotent' => EnforceIdempotency::class,
            'plan' => EnsurePlanFeature::class,
            'plan.store' => EnsureStorePlan::class,
        ]);

        // Stripe can't send a CSRF token. Its requests are checked by signature instead.
        $middleware->preventRequestForgery(except: ['stripe/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Validation failures flash input to the session, which isn't encrypted.
        $exceptions->dontFlash(['consumer_key', 'consumer_secret']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            fn (Throwable $exception, Request $request) => $request->is('api/*') ? app(ApiExceptionRenderer::class)->render($exception) : null,
        );
    })->create();
