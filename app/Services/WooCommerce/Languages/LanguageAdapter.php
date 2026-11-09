<?php

namespace App\Services\WooCommerce\Languages;

use App\Enums\ContentLanguage;
use App\Models\WooCommerceProductLink;

/**
 * Knows which remote product holds a given language. A new translation plugin is
 * supported by adding an implementation here, once its behavior has been verified.
 */
interface LanguageAdapter
{
    /**
     * @return string|null why synchronization is unavailable, or null if it is available
     */
    public function unavailableReason(): ?string;

    /**
     * @return list<ContentLanguage>
     */
    public function languages(): array;

    /**
     * @return int|null the remote product for this language, or null if there isn't one
     */
    public function remoteIdFor(WooCommerceProductLink $link, ContentLanguage $language): ?int;
}
