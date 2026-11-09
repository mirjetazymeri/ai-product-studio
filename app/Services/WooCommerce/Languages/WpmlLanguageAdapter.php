<?php

namespace App\Services\WooCommerce\Languages;

use App\Enums\ContentLanguage;
use App\Models\WooCommerceProductLink;

/**
 * Uses the translation IDs WooCommerce Multilingual reports for each product. Only
 * translations that already exist are updated. New ones are never created.
 */
final class WpmlLanguageAdapter implements LanguageAdapter
{
    public function unavailableReason(): ?string
    {
        return null;
    }

    public function languages(): array
    {
        return ContentLanguage::cases();
    }

    public function remoteIdFor(WooCommerceProductLink $link, ContentLanguage $language): ?int
    {
        if ($link->remote_language === $language->value) {
            return $link->remote_id;
        }

        $translated = $link->translations[$language->value] ?? null;

        return is_numeric($translated) ? (int) $translated : null;
    }
}
