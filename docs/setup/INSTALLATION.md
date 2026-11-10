# Installation

## Requirements

| Software | Version |
| --- | --- |
| PHP | 8.3 or newer |
| Composer | 2 |
| Node.js and npm | Node 20.19 or newer, or 22.12 or newer |
| MySQL | A current release, with an empty database |

PHP extensions: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`. The test suite also needs `pdo_sqlite`.

## Automated setup

Create an empty database called `ai_product_studio`. Migrations are additive, but always point a new install at its own database.

```bash
git clone https://github.com/mirjetazymeri/ai-product-studio.git
```

```bash
cd ai-product-studio
```

```bash
composer run setup
```

This installs dependencies, creates `.env` if it doesn't exist, generates the application key, runs migrations, and builds the frontend.

## Manual setup

```bash
composer install
```

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

Edit the `DB_*` values in `.env`, then:

```bash
php artisan migrate
```

```bash
npm install --ignore-scripts
```

```bash
npm run build
```

Never run `migrate:fresh`, `migrate:refresh` or `db:wipe` against a database with data you want to keep. They delete it.

## Running the application

```bash
php artisan serve
```

This serves <http://localhost:8000>. If you use another address, set `APP_URL` to match.

### Queue worker

```bash
php artisan queue:work
```

| Needs a worker | Runs without one |
| --- | --- |
| Bulk AI generation | Single-product generation in the web app |
| REST API generation and exports | Web exports and file imports up to the configured size |
| Larger WooCommerce imports and syncs | The WooCommerce demo store |

### Scheduler

The scheduler removes expired export files and API records, and reconciles Stripe subscriptions. It's optional locally.

```bash
php artisan schedule:work
```

## Gemini

Create a key in [Google AI Studio](https://aistudio.google.com/), then set these in `.env`:

```dotenv
GEMINI_API_KEY=your-key-here
GEMINI_MODEL=gemini-3.5-flash-lite
```

Model availability changes over time. This command looks up the configured model without generating anything:

```bash
php artisan ai:gemini-check
```

Usage is capped by `AI_GENERATIONS_PER_MINUTE`, `AI_USER_DAILY_LIMIT`, `AI_APP_DAILY_LIMIT` and each plan's monthly allowance.

## What works without credentials

| Feature | Needs |
| --- | --- |
| Accounts, products, spreadsheets, SEO analysis | Nothing |
| WooCommerce Demo Mode and Sample AI Output | Nothing |
| Demo Billing | Nothing. It's the default |
| AI generation and analysis | `GEMINI_API_KEY` and `GEMINI_MODEL` |
| Real WooCommerce store | A public HTTPS store, a REST API key, and a plan that includes it |
| Stripe | The [billing guide](../integrations/billing.md) setup |

## Windows with Laragon

1. Choose PHP 8.3 under **Menu > PHP > Version**.
2. Clone the repository into `C:\laragon\www\ai-product-studio`.
3. Start MySQL, and create the `ai_product_studio` database.
4. Open the **Terminal** from Laragon, so `php` and `composer` are on the path, and run the setup above.
5. Set `APP_URL=https://ai-product-studio.test` in `.env`, then choose **Menu > Apache > SSL > Enabled** and reload Apache.

## Environment variables

[`.env.example`](../../.env.example) lists every variable with safe defaults. Billing variables are described in the [billing guide](../integrations/billing.md).

## Running tests

```bash
php artisan test
```

See the [testing guide](../testing.md). If something doesn't work, see [troubleshooting](troubleshooting.md).
