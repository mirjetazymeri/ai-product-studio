<?php

namespace App\Services\WooCommerce\Http;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        // Some Windows setups return nothing from dns_get_record for valid names.
        if ($addresses === []) {
            $addresses = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
