# AI Product Studio REST API

Version 1 of the API lets an ERP, a Laravel project or a custom shop manage products, request AI content, and collect approved content. It is at `/api/v1` and uses the same services as the web app, so limits and approval rules are the same in both.

- [Authentication and token abilities](authentication.md)
- [Worked examples with cURL](examples.md)
- [OpenAPI 3 specification](openapi.yaml)
- [Postman collection](postman/AI-Product-Studio.postman_collection.json) and [environment](postman/AI-Product-Studio.postman_environment.json)

## What has been tested

| | Status |
| --- | --- |
| All endpoints, abilities, ownership, idempotency, limits and exports | Automated tests over in-memory SQLite |
| Bearer tokens, queue, exports and the settings page, end to end | Checked locally against a stand-in for Gemini |
| Gemini through this API | Simulated responses only. No real Gemini request has been sent through the API |
| Production deployment | Not verified |

## Typical workflow

1. Create a token under **Settings, API Access**.
2. `POST /products` to create a product, or `PATCH` one you already have.
3. `POST /products/{id}/generate` to queue AI content. You get `202` and a `generation_id`.
4. Poll `GET /generations/{id}` until `status` is `succeeded` or `failed`.
5. A person approves the draft in **Content Review**. The API can't approve content, by design.
6. `GET /products/{id}/approved-content?language=en` to collect the approved text.

## Endpoints

| Method and path | Ability | Purpose |
| --- | --- | --- |
| `GET /me` | any | The token's owner and abilities |
| `GET /products` | `products:read` | List, search, filter and sort |
| `POST /products` | `products:write` | Create |
| `GET /products/{id}` | `products:read` | Read |
| `PATCH /products/{id}` | `products:write` | Update |
| `DELETE /products/{id}` | `products:write` | Delete the local record |
| `PUT /products/{id}/external-references/{source}` | `products:write` | Map to an outside ID |
| `DELETE /products/{id}/external-references/{source}` | `products:write` | Remove a mapping |
| `GET /products/{id}/contents` | `content:read` | All saved languages |
| `GET /products/{id}/contents/{language}` | `content:read` | One language |
| `PUT /products/{id}/contents/{language}` | `content:write` | Write hand-written content |
| `GET /products/{id}/approved-content?language=` | `content:read` | Approved content only |
| `POST /ai/analyze-product` | `ai:generate` | Description to product fields |
| `POST /products/{id}/generate` | `ai:generate` | Queue generation |
| `GET /generations/{id}` | `ai:read` | Poll a generation |
| `POST /seo/analyze` | `content:read` | Rule-based SEO score |
| `POST /exports` | `exports:read` | Queue a CSV or XLSX export |
| `GET /exports/{id}` | `exports:read` | Export status |
| `GET /exports/{id}/download` | `exports:read` | Download the file |

Languages are `en`, `sq` and `de`.

## Responses

A successful response has a `data` member. Lists add `links` and `meta`.

```json
{ "data": { "id": 15, "name": "Gaming Controller Stand", "status": "draft" } }
```

Products are read as `name` and written as `original_name`, the field name the app and the analysis endpoint use.

Errors have a `message` for people and a stable `code` for programs. Validation errors list each field:

```json
{ "message": "The given data was invalid.", "errors": { "original_name": ["The product name is required."] } }
```

| Status | Typical `code` | Meaning |
| --- | --- | --- |
| 400 | `malformed_json`, `invalid_idempotency_key` | The request couldn't be read |
| 401 | `unauthenticated` | Missing, wrong, revoked or expired token |
| 403 | `insufficient_ability`, `https_required`, `plan_feature_unavailable`, `plan_limit_reached` | The token or the account's plan doesn't allow this |
| 404 | `not_found`, `no_approved_content` | Doesn't exist, or isn't yours |
| 405 | `method_not_allowed` | Wrong method |
| 409 | `content_exists`, `duplicate_request`, `linked_to_store`, `external_reference_conflict`, `export_not_ready`, `export_failed`, `idempotency_key_conflict`, `idempotency_in_progress` | Conflict with the current state |
| 410 | `export_expired` | The export file is gone |
| 422 | field errors, `missing_product_data`, `no_products` | Invalid input |
| 429 | `rate_limited`, `daily_limit_reached`, `monthly_limit_reached` | Limit reached. See `retry_after` and `Retry-After` |
| 500 | `server_error` | Something failed here. No details are returned |
| 502, 504 | AI error codes | Gemini failed or timed out during analysis |
| 503 | `ai_unavailable`, `service_unavailable` | Temporarily unavailable |

## Pagination

`GET /products` takes `page` and `per_page`, which defaults to 15 and allows up to 100.

## AI generation lifecycle

| `status` | Meaning |
| --- | --- |
| `queued` | Accepted and waiting for a worker. Already counts towards the daily quota |
| `processing` | A worker is calling Gemini |
| `succeeded` | `content` and `usage` are available, and the draft is in Content Review |
| `failed` | `error` has a code and message |

Generation needs a worker running `php artisan queue:work`. Without one, requests stay `queued`, and after 24 hours they fail instead of spending tokens.

A generation already running for the same product and language answers `409` with its `generation_id`. A retried job can't call Gemini twice, because a worker must first claim the generation.

A result is a draft. It is only saved to the product when someone approves it in Content Review, and even then existing content needs explicit confirmation before it's replaced.

### What "approved" means

The app has no separate approval flag on saved content. Saved content records its source, and `manual`, `ai` and `sample` mean a person wrote or approved it. `woocommerce` means it was imported and not yet reviewed, so it's excluded. AI drafts aren't saved content until approved, so they can't appear in `contents` or `approved-content`.

Content written with `PUT .../contents/{language}` counts as hand-written, so it's treated as approved. Only give `content:write` to clients you trust to publish text.

## Idempotency

Send an `Idempotency-Key` header on these operations to make a retry safe:

`POST /products`, `POST /products/{id}/generate`, `POST /ai/analyze-product`, `POST /exports`

| Situation | Result |
| --- | --- |
| Same key, same body, first request succeeded | The first response again, with `Idempotency-Replayed: true`. Nothing runs twice |
| Same key, different body | `409 idempotency_key_conflict` |
| Same key while the first is still running | `409 idempotency_in_progress` with `Retry-After` |
| First request failed with any non-2xx status | The key is released, so you can correct and retry |

Keys are 1 to 255 letters, numbers, or `- _ : .`. They're scoped to your account and the exact method and path, and kept for 24 hours. Only a hash of the key is stored, and the saved response is encrypted.

## External product IDs

A product can have one external ID per `source`, where `source` is your name for the outside system, such as `erp-main`.

- Set one when creating a product, with `external_reference`, or later with `PUT .../external-references/{source}`.
- Find a product with `GET /products?external_source=erp-main&external_id=A100`.
- IDs are only unique within one source and one user. An ID already used by another of your products answers `409`.
- SKUs are unique only within your own catalog, and are never matched automatically.
- Sources starting with `woocommerce-` or `shopify-` are reserved for store integrations.

## Rate limits

Each limit is per user, per minute, and can be changed in `.env`.

| Limit | Default | Setting |
| --- | --- | --- |
| All API requests | 120 | `API_RATE_LIMIT` |
| `POST /products/{id}/generate` | 20 | `API_RATE_LIMIT_GENERATE` |
| `POST /ai/analyze-product` | 10 | `API_RATE_LIMIT_ANALYZE` |
| `POST /exports` | 10 | `API_RATE_LIMIT_EXPORTS` |
| Creating tokens in Settings | 10 | `API_RATE_LIMIT_TOKEN_CREATION` |
| Failed authentication, per IP address | 30 | `API_RATE_LIMIT_FAILED_AUTH` |

The Gemini limits still apply on top, and are shared with the web app: `AI_GENERATIONS_PER_MINUTE`, `AI_USER_DAILY_LIMIT` and `AI_APP_DAILY_LIMIT`. Queued generations wait for the per-minute limit instead of failing.

## Exports

`POST /exports` returns an `export_id` to poll. `approved_only` defaults to `true`. Setting it to `false` adds saved text imported from a store. Drafts are never exported through the API.

Downloads need the token, and files expire after `CATALOG_RETENTION_DAYS`, which defaults to 7. Export needs a queue worker.

## Plans

The API needs a plan that includes it, Pro or Agency, as well as the right token ability. Other plans get `403 plan_feature_unavailable` on every endpoint except `GET /me`, which also reports the plan and this month's usage. Each successful generation or analysis uses one unit of the monthly allowance. See the [billing guide](../billing.md).

## Security notes

- HTTPS is required when `APP_ENV=production`. Behind a proxy, configure Laravel's trusted proxies so HTTPS is detected.
- Only Bearer tokens are accepted. A browser session can't use the API.
- Store credentials, prompts and file paths are never returned.
- Treat product text and AI output as untrusted, and escape them when you display them.
- Demo Mode products aren't part of the API.
- Deleting a product never changes a connected store.

## Local testing

```bash
php artisan queue:work
```

Run the worker in one terminal, then use the [examples](examples.md) with `https://ai-product-studio.test/api/v1`. Laragon's local certificate may need `curl --insecure` or Laragon's root certificate.

The automated tests use in-memory SQLite and faked HTTP:

```bash
php artisan test tests/Feature/Api
```
