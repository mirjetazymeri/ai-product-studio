<?php

namespace App\Services\WooCommerce\Languages;

use App\Enums\ContentLanguage;
use App\Models\WooCommerceProductLink;

/**
 * A store with no translation plugin has exactly one language.
 */
final class SingleLanguageAdapter implements LanguageAdapter
{
    public function __construct(private readonly ?ContentLanguage $language) {}

    public function unavailableReason(): ?string
    {
        return $this->language === null ? 'Import the store\'s products first, so you can choose which language the store is written in.' : null;
    }

    public function languages(): array
    {
        return $this->language === null ? [] : [$this->language];
    }

    public function remoteIdFor(WooCommerceProductLink $link, ContentLanguage $language): ?int
    {
        return $language === $this->language ? $link->remote_id : null;
    }
}
