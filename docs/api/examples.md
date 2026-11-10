# API examples

These assume:

```bash
export BASE_URL="https://ai-product-studio.test/api/v1"
export API_TOKEN="YOUR_API_TOKEN"
```

Responses are shortened.

## Check the token

```bash
curl "$BASE_URL/me" -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json"
```

```json
{
  "data": {
    "id": 1,
    "name": "Example User",
    "email": "user@example.com",
    "token": { "name": "ERP connector", "abilities": ["products:read", "products:write"], "expires_at": null }
  }
}
```

## Create a product safely

The key makes a network retry return the same product.

```bash
curl -X POST "$BASE_URL/products" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: erp-import-A100" \
  -d '{
    "original_name": "Gaming Controller Stand",
    "sku": "GCS-100",
    "category": "Gaming Accessories",
    "material": "PLA",
    "details": "Holds two PS5 controllers.",
    "external_reference": { "source": "erp-main", "external_id": "A100" }
  }'
```

```json
{
  "data": {
    "id": 15,
    "name": "Gaming Controller Stand",
    "sku": "GCS-100",
    "status": "draft",
    "languages": [],
    "external_references": [{ "source": "erp-main", "external_id": "A100" }]
  }
}
```

## Find a product by its outside ID

```bash
curl "$BASE_URL/products?external_source=erp-main&external_id=A100" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json"
```

## List products

```bash
curl "$BASE_URL/products?status=draft&language=en&sort=-updated_at&per_page=25" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json"
```

## Update a product

```bash
curl -X PATCH "$BASE_URL/products/15" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "status": "published" }'
```

## Analyze a description

This uses one Gemini request and creates nothing.

```bash
curl -X POST "$BASE_URL/ai/analyze-product" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "prompt": "A 3D printed gaming controller stand for PS5 controllers, made from PLA, SKU GCS-100." }'
```

```json
{
  "data": {
    "original_name": "Gaming Controller Stand",
    "sku": "GCS-100",
    "category": "Gaming Accessories",
    "material": "PLA",
    "details": "A 3D printed stand for PS5 controllers.",
    "status": "draft"
  }
}
```

## Generate content

```bash
curl -i -X POST "$BASE_URL/products/15/generate" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Idempotency-Key: generate-15-en-1" \
  -d '{ "language": "en", "custom_instructions": "Mention that it fits two controllers." }'
```

```text
HTTP/1.1 202 Accepted
```

```json
{ "data": { "generation_id": 123, "status": "queued", "product_id": 15, "language": "en" } }
```

## Poll the generation

```bash
curl "$BASE_URL/generations/123" -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json"
```

```json
{
  "data": {
    "generation_id": 123,
    "product_id": 15,
    "language": "en",
    "status": "succeeded",
    "content": { "title": "Gaming Controller Stand for Two PS5 Controllers", "slug": "gaming-controller-stand" },
    "review_status": "pending",
    "usage": { "input_tokens": 120, "output_tokens": 80, "total_tokens": 220 },
    "error": null
  }
}
```

## Fetch approved content

After someone approves the draft in Content Review:

```bash
curl "$BASE_URL/products/15/approved-content?language=en" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json"
```

Before that, the response is:

```json
{ "message": "This product has no approved content in that language.", "code": "no_approved_content" }
```

## Write content by hand

```bash
curl -X PUT "$BASE_URL/products/15/contents/de" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "title": "Controller-Halterung", "short_description": "Hält zwei Controller." }'
```

Replacing content that wasn't hand-written needs `"confirm_overwrite": true`, or the response is `409 content_exists`.

## SEO analysis

No Gemini request and no quota.

```bash
curl -X POST "$BASE_URL/seo/analyze" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "product_name": "Controller Stand", "title": "Gaming Controller Stand for PS5", "keywords": ["controller stand"] }'
```

```json
{
  "data": {
    "score": 31,
    "field_feedback": [{ "key": "title", "field": "title", "points": 15, "max": 15, "status": "good", "message": "Length and relevance look good." }],
    "warnings": [],
    "suggestions": ["Add a meta title."]
  }
}
```

## Export products

```bash
curl -X POST "$BASE_URL/exports" \
  -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Idempotency-Key: nightly-export-2026-11-10" \
  -d '{ "format": "xlsx", "languages": ["en", "de"], "approved_only": true, "product_ids": [15, 16] }'
```

```json
{ "data": { "export_id": 42, "status": "queued", "format": "xlsx" } }
```

```bash
curl "$BASE_URL/exports/42" -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json"
```

```bash
curl -o products.xlsx "$BASE_URL/exports/42/download" -H "Authorization: Bearer $API_TOKEN"
```

## Delete a product

```bash
curl -X DELETE "$BASE_URL/products/15" -H "Authorization: Bearer $API_TOKEN" -H "Accept: application/json"
```

A product linked to a store answers `409 linked_to_store`. Add `?unlink_stores=true` to delete the local record and its link. The store's own product is never changed.

## A Laravel client

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$api = Http::baseUrl(config('services.product_studio.url'))
    ->withToken(config('services.product_studio.token'))
    ->acceptJson();

$generation = $api
    ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
    ->post('products/15/generate', ['language' => 'en'])
    ->throw()
    ->json('data');

$result = $api->get("generations/{$generation['generation_id']}")->throw()->json('data');
```
