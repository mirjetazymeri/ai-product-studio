<?php

namespace App\Services\WooCommerce\Languages;

use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Capabilities\CapabilityDetector;

final class LanguageAdapterFactory
{
    public function for(WooCommerceStore $store): LanguageAdapter
    {
        $capability = $store->capabilities['language'] ?? null;

        if ($capability === null) {
            return new UnsupportedLanguageAdapter('Test the connection first, so the store\'s language setup can be checked.');
        }

        if (! ($capability['supported'] ?? false)) {
            return new UnsupportedLanguageAdapter((string) ($capability['reason'] ?? 'This store\'s translation setup isn\'t supported.'));
        }

        return $capability['provider'] === CapabilityDetector::LANGUAGE_WPML
            ? new WpmlLanguageAdapter
            : new SingleLanguageAdapter($store->content_language);
    }
}
