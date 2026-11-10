<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * A billing action failed. The message is written for the user and is safe to show.
 */
final class BillingException extends RuntimeException {}
