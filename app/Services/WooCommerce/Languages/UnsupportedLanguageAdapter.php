<?php

namespace App\Services\WooCommerce\Languages;

use App\Enums\ContentLanguage;
use App\Models\WooCommerceProductLink;

final class UnsupportedLanguageAdapter implements LanguageAdapter
{
    public function __construct(private readonly string $reason) {}

    public function unavailableReason(): ?string
    {
        return $this->reason;
    }

    public function languages(): array
    {
        return [];
    }

    public function remoteIdFor(WooCommerceProductLink $link, ContentLanguage $language): ?int
    {
        return null;
    }
}
