# WooCommerce Demo Mode

Demo Mode lets you try the whole WooCommerce integration without a store, an account or API keys. You connect a simulated store, import fictional products, review content, preview a synchronization, run it, and see the simulated product change.

It isn't a mockup. Import, preview, conflict detection, synchronization, history and rollback are the same classes a real store uses. Only the last step differs: instead of sending an HTTP request, the demo reads and writes its own database rows.

> **What has and hasn't been tested.** The integration has only been tested against simulated stores and mocked API responses. It has not been verified against a production or staging WooCommerce store.

## Five-minute walkthrough

Sign in, then:

1. Open **Integrations** and choose **Try WooCommerce Demo**. No address or credentials are requested.
2. Under *Walkthrough*, choose **Import Demo Products**, then confirm the import. You'll see one record reported as an error on purpose.
3. Choose **Generate Sample AI Output**. This makes no Gemini request and uses no tokens.
4. In **Content Review**, tick the drafts and **Replace saved content where it exists**, then choose **Approve selected**. To edit or reject a draft, choose **Review** instead.
5. Open **Linked Products**, select an approved product, and choose **Sync Content**, then **Preview changes**.
6. Review the field-by-field comparison and choose **Run simulated synchronization**.
7. Choose **View simulated product**. The title and descriptions have changed, and each protected field shows **Unchanged**.
8. Open **Sync History** to see what changed, marked **Simulation**.

To see a conflict, repeat steps 5 and 6, but choose **Simulate Remote Change** before confirming. The sync stops without overwriting anything and shows the original value, the new store value and the proposed value. **Refresh preview** rebuilds the comparison from the new value.

When you're done, use **Reset Demo Store** at the bottom of the demo page.

## Sample AI and real Gemini

| | Sample AI Output (default) | Real Gemini |
| --- | --- | --- |
| Started from | Demo page, Linked Products, or demo product page | Demo product page only, after ticking a confirmation |
| External request | None | One real Gemini request |
| API key needed | No | Yes |
| Tokens and daily quota | None | Normal tokens, quota and rate limit |
| Generation History | Not listed | Listed |
| Label | "Sample AI Output" | "Real Gemini" |

Sample text is built from fixed templates, so the same product always gets the same result. It's never described as Gemini output, including after approval. Demo products can't reach Gemini by any other route: the normal generate endpoint refuses them.

## Simulated and real integration

| | Real store | Demo store |
| --- | --- | --- |
| Transport | `RealWooCommerceTransport` (HTTPS) | `DemoWooCommerceTransport` (database) |
| Credentials | Encrypted Consumer Key and Secret | None |
| Network | Public HTTPS with SSRF protection | None: the transport has no HTTP client |
| Product data | The store | `woocommerce_demo_products` |
| Mapping, import, preview, conflicts, sync, rollback | Shared | Shared |
| Field allowlist | Shared | Shared, and enforced again by the transport |
| Queue worker | Needed for larger jobs | Never needed |
| Languages | Single-language or WPML | English only |

The demo can't tell you how a real store behaves: real authentication, security plugins, host limits, HTML filtering by WordPress, or WPML updates. Before relying on the integration, run a separately approved test on a staging store.

## What's in the sample catalog

There are 13 fictional products across Gaming Accessories, Home Decor, Personalized Gifts, Office Accessories, Phone Accessories and Keychains, covering:

- complete, weak and missing descriptions
- two products with the same name and different IDs
- missing SKUs
- existing SEO metadata, which is shown but never changed
- publish, draft, pending and private statuses
- a variable product with three variations
- a grouped product, which is imported as read-only

A 14th record has no name, so the import can show how a bad record is reported. The API returns seven records per page, so an import reads two pages.

## Protected fields

A sync can only send the title, long description, short description and URL slug. Prices, stock quantities, SKU, images, status, variations, shipping and tax are outside the allowlist. Each demo product page compares those fields with the original catalog record, so the claim can be checked on screen.

SEO metadata shows as "SEO Sync Not Supported", matching a real store.

## Error simulation

- **Simulate Outage** makes every simulated request answer HTTP 503.
- A product ID that doesn't exist answers 404, and a write with no permitted fields answers 400.

These pass through the same status-to-error mapping as real responses.

## Isolation

- A demo store has `connection_type = demo` and an address on the reserved `.invalid` domain, which real stores are refused.
- Each user has their own demo store and copy of the catalog. All demo routes check ownership.
- Imported products are marked with `demo_store_id`. `User::products()` excludes them, which keeps them out of the product list, dashboard, exports, file imports and SKU matching.
- Demo products have no local SKU and never match a real product by SKU, so they can't take over a real product's mapping. The sample SKU stays on the store link.
- Demo products are reached from the demo pages. They use the normal product page and Content Review, labelled as demo.

## What Reset Demo Store does

Reset restores the original catalog and removes, for that demo store only: its demo products and their content, sample drafts, imports, links and sync history. It also ends a simulated outage.

It keeps real stores, real products, real generation history and settings. A real Gemini request made for a demo product stays in Generation History and still counts towards that day's quota.

## Code map

| Area | Location |
| --- | --- |
| Transport boundary | `app/Services/WooCommerce/Transport/` |
| Choosing a transport, field allowlist | `app/Services/WooCommerce/WooCommerceService.php` |
| Catalog, setup, reset, conflict simulation, sample text | `app/Services/WooCommerce/Demo/` |
| Demo pages | `app/Http/Controllers/Integrations/WooCommerceDemoController.php`, `resources/views/integrations/demo/` |
| Tests | `tests/Feature/WooCommerce/DemoModeTest.php` |

Tests run on in-memory SQLite and fail if any outside HTTP request is sent:

```bash
php artisan test --filter=DemoModeTest
```
