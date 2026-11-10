<?php

namespace App\Services\Billing\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The user's plan doesn't allow this. API clients get a JSON 403, and browsers go to
 * the billing page, where the reason and the upgrade options are shown together.
 */
final class PlanRestrictionException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly string $feature, public readonly string $plan)
    {
        parent::__construct($message);
    }

    public function render(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'code' => $this->errorCode,
                'feature' => $this->feature,
                'plan' => $this->plan,
            ], 403);
        }

        return to_route('billing.index')->with('plan_notice', $this->getMessage());
    }
}
