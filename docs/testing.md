# Testing

The suite uses PHPUnit through Laravel's test runner.

```bash
php artisan test
```

```bash
php artisan test tests/Feature/Billing
```

## Isolation

- `phpunit.xml` selects in-memory SQLite.
- `tests/TestCase.php` refuses to boot on anything else, so a stale configuration cache can't reach a development database.
- The queue, cache and session use in-memory or synchronous drivers.

## External services

No test can reach a real service.

| Service | How it's replaced |
| --- | --- |
| Gemini and WooCommerce | Laravel's `Http::fake`. `Http::preventStrayRequests` fails any request a test didn't set up |
| Stripe | A fake of the Stripe SDK's HTTP client, set for every test, which fails on any unexpected request |
| Stripe webhooks | Real signature checks against locally signed payloads |
| DNS for store addresses | A fake resolver |

## What's covered

| Directory | Focus |
| --- | --- |
| `tests/Feature/Auth`, `Products` | Accounts, product management, ownership |
| `tests/Feature/AI`, `tests/Unit/AI` | Prompts, Gemini errors, generation, bulk work, SEO and quality checks |
| `tests/Feature/Catalog` | Spreadsheet import, mapping, duplicates, export |
| `tests/Feature/WooCommerce` | SSRF protection, import, sync, conflicts, rollback, demo isolation |
| `tests/Feature/Api` | Tokens, abilities, ownership, idempotency, limits, and specification accuracy |
| `tests/Feature/Billing` | Entitlements, metering, enforcement, demo isolation, webhooks |

`phpunit.xml` sets `BILLING_DEFAULT_PLAN=agency`, so feature tests can reach paid features. Billing tests set the real default themselves.

`ApiDocumentationTest` fails if the OpenAPI specification stops matching the routes and their abilities.

## What tests can't show

SQLite doesn't reproduce MySQL's locking, so concurrency guarantees were also checked separately, with competing processes on a scratch MySQL database. The tests also can't show how a real WooCommerce store or Stripe account behaves. See [Limitations](../README.md#limitations).

## Code style

```bash
vendor/bin/pint --test app routes tests database config
```

Continuous integration runs the tests, the style check and the frontend build. See [`.github/workflows/tests.yml`](../.github/workflows/tests.yml).
