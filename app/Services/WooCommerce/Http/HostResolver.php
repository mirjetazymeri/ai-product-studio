<?php

namespace App\Services\WooCommerce\Http;

interface HostResolver
{
    /**
     * @return list<string> every IPv4 and IPv6 address the host currently resolves to
     */
    public function resolve(string $host): array;
}
