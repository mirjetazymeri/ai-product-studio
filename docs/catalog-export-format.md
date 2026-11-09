# Catalog export format

AI Product Studio exports CSV (UTF-8 with a byte order mark) and Excel `.xlsx` files in one generic layout. The first row contains the column names.

## Layout

There is one row per product and language. A product with English and German content has two rows that share the same `local_product_id`. A product with no content in the selected scope still gets one row with empty content columns.

"Product information only" exports leave out the content columns and have one row per product.

Excel files show each column name as a label, such as `Product Name` for `product_name`, and have a frozen header row, filters, set column widths and fixed row heights. Long text stays complete in its cell and is visible in Excel's formula bar. CSV files use the column names exactly as listed below.

## Columns

| Column | Description |
|---|---|
| `local_product_id` | This application's product ID. Never an ID from another system. |
| `external_source` | The system an external ID came from, such as a file label. |
| `external_id` | The product's ID in that system. Read it together with `external_source`. |
| `product_name` | Product name |
| `sku` | SKU |
| `category` | Category |
| `material` | Material |
| `details` | Product details |
| `status` | `draft` or `published` |
| `language` | `en`, `sq` (Albanian) or `de` |
| `title` | Content title |
| `short_description` | Short description |
| `long_description` | Long description |
| `meta_title` | SEO meta title |
| `meta_description` | SEO meta description |
| `slug` | URL slug |
| `keywords` | Comma-separated keywords |
| `content_source` | `manual` or `ai` |
| `content_status` | `approved` (saved to the product) or `pending_review` (an AI draft not yet approved) |

## Content options

| Option | Includes |
|---|---|
| Product information only | No content columns |
| Product information with AI-generated content | Saved content with `content_source` of `ai`, plus AI drafts waiting for review |
| Approved content only | All content saved to the product: written by hand, imported, or approved AI content |
| All existing content | All saved content, plus AI drafts waiting for review |

## Spreadsheet safety

Text that a spreadsheet could run as a formula, meaning text starting with `=`, `+`, `-` or `@`, a tab or a carriage return, is prefixed with an apostrophe. Importing a file exported by this application removes that apostrophe again.

## Adding another format

A format for a specific platform is a new class implementing `App\Services\Catalog\Export\CatalogExportFormat`. The exporter, file writer and security handling stay the same.
