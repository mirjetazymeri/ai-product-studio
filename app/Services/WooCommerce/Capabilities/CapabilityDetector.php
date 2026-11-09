<?php

namespace App\Services\WooCommerce\Capabilities;

use App\Enums\ContentLanguage;
use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceService;

/**
 * Works out which language and SEO plugins a store uses, using read-only requests.
 */
final class CapabilityDetector
{
    public const LANGUAGE_NONE = 'none';

    public const LANGUAGE_WPML = 'wpml';

    private const LANGUAGE_PLUGINS = [
        'sitepress-multilingual-cms' => self::LANGUAGE_WPML,
        'woocommerce-multilingual' => self::LANGUAGE_WPML,
        'polylang' => 'polylang',
        'polylang-pro' => 'polylang',
        'polylang-wc' => 'polylang',
        'translatepress-multilingual' => 'translatepress',
        'weglot' => 'weglot',
    ];

    private const LANGUAGE_NAMESPACES = ['pll/' => 'polylang', 'wpml/' => self::LANGUAGE_WPML];

    private const SEO_PLUGINS = [
        'wordpress-seo' => 'yoast',
        'wordpress-seo-premium' => 'yoast',
        'seo-by-rank-math' => 'rank_math',
        'seo-by-rank-math-pro' => 'rank_math',
        'all-in-one-seo-pack' => 'aioseo',
        'all-in-one-seo-pack-pro' => 'aioseo',
    ];

    private const SEO_NAMESPACES = ['yoast/' => 'yoast', 'rankmath/' => 'rank_math', 'aioseo/' => 'aioseo'];

    private const SEO_NAMES = ['yoast' => 'Yoast SEO', 'rank_math' => 'Rank Math', 'aioseo' => 'All in One SEO'];

    public function __construct(private readonly WooCommerceService $woocommerce) {}

    /**
     * @param  array<string, mixed>|null  $sampleProduct  a product from a default listing
     * @return array<string, mixed>
     */
    public function detect(WooCommerceStore $store, ?array $sampleProduct): array
    {
        [$plugins, $version] = $this->activePlugins($store);
        $namespaces = $this->namespaces($store);

        return [
            'woocommerce_version' => $version,
            'language' => $this->languageCapability($plugins, $namespaces, $sampleProduct),
            'seo' => $this->seoCapability($plugins, $namespaces),
        ];
    }

    /**
     * @return array{list<string>, ?string}
     */
    private function activePlugins(WooCommerceStore $store): array
    {
        try {
            $status = $this->woocommerce->systemStatus($store);
        } catch (WooCommerceException) {
            // Needs extra permissions. Namespaces below still give a useful answer.
            return [[], null];
        }

        $plugins = [];

        foreach ((array) ($status['active_plugins'] ?? []) as $plugin) {
            if (is_array($plugin) && is_string($plugin['plugin'] ?? null)) {
                $plugins[] = explode('/', $plugin['plugin'])[0];
            }
        }

        $version = $status['environment']['version'] ?? null;

        return [$plugins, is_string($version) ? substr($version, 0, 20) : null];
    }

    /**
     * @return list<string>
     */
    private function namespaces(WooCommerceStore $store): array
    {
        try {
            $namespaces = $this->woocommerce->siteIndex($store)['namespaces'] ?? [];
        } catch (WooCommerceException) {
            return [];
        }

        return is_array($namespaces) ? array_values(array_filter($namespaces, 'is_string')) : [];
    }

    /**
     * @param  list<string>  $plugins
     * @param  list<string>  $namespaces
     * @return array<string, mixed>
     */
    private function languageCapability(array $plugins, array $namespaces, ?array $sample): array
    {
        $provider = $this->find($plugins, $namespaces, self::LANGUAGE_PLUGINS, self::LANGUAGE_NAMESPACES);

        if ($provider === null) {
            return ['provider' => self::LANGUAGE_NONE, 'supported' => true, 'default' => null, 'reason' => null];
        }

        if ($provider !== self::LANGUAGE_WPML) {
            return [
                'provider' => $provider,
                'supported' => false,
                'default' => null,
                'reason' => 'This translation plugin\'s REST behavior can\'t be verified, so synchronization is turned off to avoid overwriting the wrong language. Your local translations are kept.',
            ];
        }

        // WooCommerce Multilingual documents these fields on product responses.
        if ($sample === null || ! is_string($sample['lang'] ?? null) || ! is_array($sample['translations'] ?? null)) {
            return [
                'provider' => $provider,
                'supported' => false,
                'default' => null,
                'reason' => 'WPML was found, but products don\'t include language data. WooCommerce Multilingual must be active, and the store needs at least one product. Test the connection again afterwards.',
            ];
        }

        $default = ContentLanguage::tryFrom($sample['lang']);

        return ['provider' => $provider, 'supported' => true, 'default' => $default?->value, 'reason' => null];
    }

    /**
     * No SEO plugin documents a way to write product metadata through the REST API,
     * so nothing here enables writing, and no metadata keys are guessed.
     *
     * @param  list<string>  $plugins
     * @param  list<string>  $namespaces
     * @return array<string, mixed>
     */
    private function seoCapability(array $plugins, array $namespaces): array
    {
        $provider = $this->find($plugins, $namespaces, self::SEO_PLUGINS, self::SEO_NAMESPACES);

        return [
            'provider' => $provider,
            'name' => self::SEO_NAMES[$provider] ?? null,
            'writable' => false,
            'reason' => $provider === null
                ? 'No supported SEO plugin was detected.'
                : (self::SEO_NAMES[$provider].' has no documented way to update product metadata through the REST API.'),
        ];
    }

    /**
     * @param  list<string>  $plugins
     * @param  list<string>  $namespaces
     * @param  array<string, string>  $pluginMap
     * @param  array<string, string>  $namespaceMap
     */
    private function find(array $plugins, array $namespaces, array $pluginMap, array $namespaceMap): ?string
    {
        foreach ($plugins as $plugin) {
            if (isset($pluginMap[$plugin])) {
                return $pluginMap[$plugin];
            }
        }

        foreach ($namespaces as $namespace) {
            foreach ($namespaceMap as $prefix => $provider) {
                if (str_starts_with($namespace, $prefix)) {
                    return $provider;
                }
            }
        }

        return null;
    }
}
