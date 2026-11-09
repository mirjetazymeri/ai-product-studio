<?php

namespace App\Services\WooCommerce\Exceptions;

use App\Enums\WooCommerceError;
use RuntimeException;

final class WooCommerceException extends RuntimeException
{
    public function __construct(
        public readonly WooCommerceError $error,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($error->value);
    }
}
