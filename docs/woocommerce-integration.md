# WooCommerce integration

No store is needed to try this integration: see [WooCommerce Demo Mode](woocommerce-demo-mode.md). All requests leave through `RealWooCommerceTransport`, and `WooCommerceService` selects it for every store that isn't a demo store.

## What is sent to a store

Only `name`, `description`, `short_description` and `slug` can ever be written, as a partial `PUT /wp-json/wc/v3/products/{id}`. The allowlist is enforced in `WooCommerceService::updateProduct`. Prices, stock, images, SKU, status, shipping, tax, attributes, variations and downloads are never sent.

Imports, connection tests and previews use read requests only.

## Which content can be sent

Only content a person wrote or approved: `product_contents` records with a source of `manual` or `ai`. Text imported from the store has the source `woocommerce` and can't be sent back until someone edits and saves it.

## Conflict detection and its limits

When a preview is built, a hash of each remote field is stored. Immediately before each write the product is read again, and any selected field that no longer matches stops that whole product as a conflict.

**WooCommerce has no conditional update.** There is no ETag or `If-Match` support on this endpoint, so the check and the write are two separate requests. An edit made in the moment between them can be overwritten. The window is short, but this is not an atomic compare-and-swap.

Writes are never retried automatically, because a lost response doesn't show whether the store applied them. A manual retry reads the store first: a field that already has the proposed value counts as done.

Rollback restores recorded previous values one product at a time. It isn't atomic across products, and it refuses fields that changed after the sync.

## Language support

| Store | Behavior |
|---|---|
| No translation plugin | One language, chosen when importing. |
| WPML with WooCommerce Multilingual | Uses the documented `lang` and `translations` product fields to find the product for each language. |
| Polylang, TranslatePress, Weglot and others | Synchronization is turned off, because their REST behavior can't be verified. |

Translations that don't exist in the store are never created. WPML documents reading and creating translated products, but not updating them. Updates use WooCommerce's standard product endpoint with the translation's own ID, so test this on a staging store first.

A new translation plugin is added by implementing `App\Services\WooCommerce\Languages\LanguageAdapter`.

## SEO metadata

SEO fields stay in this application and are shown as "SEO Sync Not Supported". Yoast documents its REST API as read-only, and no official product-metadata write endpoint was found for Rank Math. No metadata keys are guessed.

## Outbound request safety

- Only HTTPS on port 443 is accepted, and TLS verification can't be turned off.
- Hostnames are resolved before every request. If any address is private, loopback, link-local, reserved or a known metadata address, the request is refused.
- The connection is pinned to the checked address, which prevents DNS rebinding.
- Redirects are never followed, so credentials can't be forwarded to another host.
- Credentials are encrypted at rest, sent only in the `Authorization` header, and removed on disconnect.

## Running background work

Large imports and synchronizations need a queue worker:

```
php artisan queue:work
```
