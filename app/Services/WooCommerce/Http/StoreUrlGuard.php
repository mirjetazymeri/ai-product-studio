<?php

namespace App\Services\WooCommerce\Http;

use App\Enums\WooCommerceError;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Server-side request forgery protection. Addresses are checked again before every
 * request, and the connection is pinned to the checked address, so a DNS record that
 * changes between the check and the connection can't redirect it to an internal host.
 */
final class StoreUrlGuard
{
    private const BLOCKED_SUFFIXES = ['localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'test', 'invalid'];

    private const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // Azure's platform and metadata endpoint is a public address.
        '168.63.129.16/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return string the store's canonical base address, without a trailing slash
     *
     * @throws WooCommerceException
     */
    public function normalise(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new WooCommerceException(WooCommerceError::InvalidUrl);
        }

        if (strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new WooCommerceException(WooCommerceError::InvalidUrl);
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $this->ensureSafeHostname($host);

        $path = rtrim((string) preg_replace('#/wp-json.*$#i', '', $parts['path'] ?? ''), '/');

        return 'https://'.$host.$path;
    }

    /**
     * @return string one address that passed the checks, for pinning the connection
     *
     * @throws WooCommerceException
     */
    public function safeAddressFor(string $host): string
    {
        $this->ensureSafeHostname($host);

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new WooCommerceException(WooCommerceError::NetworkError);
        }

        // Every address must be public, because any of them could be used.
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false || IpUtils::checkIp($address, self::BLOCKED_RANGES)) {
                throw new WooCommerceException(WooCommerceError::UnsafeAddress);
            }
        }

        return $addresses[0];
    }

    private function ensureSafeHostname(string $host): void
    {
        // A real top-level domain is never numeric, which rules out forms such as 0x7f.1.
        if (! str_contains($host, '.') || str_contains($host, ':')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\.(\d+|0x[0-9a-f]+)$/', $host) === 1) {
            throw new WooCommerceException(WooCommerceError::UnsafeAddress);
        }

        $suffix = substr((string) strrchr($host, '.'), 1);

        if (in_array($suffix, self::BLOCKED_SUFFIXES, true)) {
            throw new WooCommerceException(WooCommerceError::UnsafeAddress);
        }
    }
}
