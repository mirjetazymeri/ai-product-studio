<?php

namespace App\Enums;

/**
 * Work that uses the monthly AI allowance. Each is one unit when it succeeds.
 * Failed requests, Sample AI Output and billing actions aren't here because they use none.
 */
enum UsageAction: string
{
    case Generation = 'ai.generation';
    case Regeneration = 'ai.regeneration';
    case BulkItem = 'ai.bulk_item';
    case Analysis = 'ai.analysis';

    /** Added by the Demo Billing control for testing limits. No AI work took place. */
    case DemoSimulated = 'demo.simulated_usage';
}
