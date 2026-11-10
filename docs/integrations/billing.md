# SaaS subscriptions and billing

> **Verification status.** Real Stripe payment processing has **not** been verified. No Stripe Test Mode integration test has been run, because no Stripe keys were available. Everything Stripe-related has been tested only with a fake of Stripe's network layer and locally signed webhooks. Demo Billing Mode is fully working, and it's a simulation.

## Architecture

| Piece | Responsibility |
| --- | --- |
| `config/billing.php` | Plans, features, limits, display prices and Stripe price mappings |
| `SubscriptionPlanService` | Reads plan definitions |
| `BillingManager` | Chooses the provider from server configuration only |
| `BillingProvider` | The interface implemented by `DemoBillingProvider`, `StripeBillingProvider` and `DisabledBillingProvider` |
| `PlanEntitlementService` | Answers "may this user do this?" and explains refusals |
| `UsageMeteringService` | Reserves, consumes and releases monthly AI units |
| `BillingOverviewService` | The figures shown on pages and by `GET /api/v1/me` |
| `StripeWebhookLedger` | Duplicate and out-of-order webhook protection |

Controllers don't contain plan names. To change a plan, edit `config/billing.php`.

## Plans

These are example prices, not validated commercial pricing.

| | Free | Starter | Pro | Agency |
| --- | --- | --- | --- | --- |
| Price per month | €0 | €9 | €24 | €59 |
| AI generations per month | 10 | 50 | 300 | 1,000 |
| Products | 20 | 200 | 2,000 | 10,000 |
| Languages | 3 | 3 | 3 | 3 |
| CSV and Excel | Basic, 50 rows per import | Included | Included | Included |
| Bulk AI | No | 10 items per batch | Included | Included |
| WooCommerce | No | No | Included | Included |
| Shopify | No | No | Coming Soon | Coming Soon |
| REST API | No | No | Included | Included |
| Agency features | No | No | No | Coming Soon |

Shopify and agency features don't exist in this codebase, so they are shown as Coming Soon and are never granted.

## Entitlements

Feature keys are `ai.generate`, `ai.analyze`, `ai.bulk`, `products.create`, `catalog.import`, `catalog.export`, `integrations.woocommerce`, `integrations.shopify`, `api.access` and `agency.tools`.

Checks sit where work happens, so no route can avoid them:

| Rule | Enforced in |
| --- | --- |
| Product limit | `ProductService::create`, used by the web form, API, file imports and store imports |
| AI features and allowance | `ProductContentGenerator` and `ProductInformationExtractor` |
| Bulk AI | Route, batch start, and again before each queued item |
| Real WooCommerce stores | Route, and in `WooCommerceService`, which every store request passes through |
| REST API | Route group. Token abilities are checked as well, and both must pass |

Viewing products, content, history and billing, editing products, exporting through the web app, cancelling, and disconnecting a store work on every plan.

WooCommerce Demo Mode is available on every plan. It's a local simulation, and its sample products don't count towards the product limit.

## Usage metering

The allowance is counted in units.

| Action | Units |
| --- | --- |
| Single content generation | 1 |
| Regeneration | 1 |
| Bulk generation | 1 per product and language |
| Product analysis | 1 |
| Failed request | 0 |
| Sample AI Output | 0 |
| SEO analysis | 0 |
| Plan changes and other simulated billing actions | 0 |

The Demo Billing **Use up AI allowance** control adds units marked as simulated, so limits can be tested without Gemini. **Reset demo billing** gives them back.

**Lifecycle.** A unit is reserved before Gemini is called, then consumed on success or released on failure. Queued work holds its unit while it waits.

**Concurrency.** A single conditional `UPDATE ... WHERE used <= allowance - quantity` takes the unit, so simultaneous requests can't both get the last one. Product creation locks the user's row while counting.

**Idempotency.** Each reservation has a unique key per user, such as `generation:123`. A retried job or request finds the existing reservation. Reuse of an API `Idempotency-Key` doesn't run the work again at all.

**Periods.** Every plan uses calendar months in UTC, whatever the payment date. All timestamps are stored in UTC. A Stripe invoice period can therefore differ from the usage period. A new month starts a new counter, and no history is deleted. A plan change applies its allowance immediately to the same month's usage.

### How the limits combine

An AI request must pass all of these:

1. Google's own quota, which local configuration can't raise.
2. The application-wide daily limit, `AI_APP_DAILY_LIMIT`.
3. The per-minute limit, `AI_GENERATIONS_PER_MINUTE`.
4. The per-user daily limit, `AI_USER_DAILY_LIMIT`.
5. The plan's monthly allowance.

A failed request can still count towards the daily safety limits, as it did before, but not towards the monthly allowance.

## Subscription states

| Status | Access |
| --- | --- |
| Free | Default plan |
| Trialing, Active | The subscribed plan |
| Past Due | The subscribed plan for `BILLING_PAST_DUE_GRACE_DAYS` (default 7), then the default plan |
| Canceling | The subscribed plan until the period ends |
| Canceled, Expired, Incomplete | Default plan |

Stripe's `unpaid` status is treated as Canceled. A subscription with a price not in the configuration grants nothing.

Downgrading, cancellation and expiration never delete anything. Products over a lower limit stay readable and editable, store connections stay in place, and only new actions are limited.

## Existing accounts

The migrations only add tables and columns. Accounts with no subscription resolve to the default plan, Free. Past AI requests aren't counted, because counters start empty. Token counts and history are unchanged.

To give existing users something more generous, set `BILLING_DEFAULT_PLAN`. It applies to everyone without a subscription.

Existing users on Free can't start new work on real WooCommerce stores or use the REST API until they move to a plan that includes them.

## Demo Billing Mode

Set `BILLING_PROVIDER=demo`, which is the default. It needs no Stripe keys and makes no network calls.

1. Open **Billing & Subscription**.
2. Choose a plan under **Change plan**, or use the pricing page.
3. Tick the confirmation and choose **Confirm simulation**.
4. Use **Simulation controls** to try a payment failure, expiration, a used-up allowance, restoring Free, or a reset.

Isolation:

- Demo records live in their own tables and use `DEMO-SUB-` and `DEMO-TXN-` references, which can't match a Stripe ID.
- The provider comes from the server's environment. No request can select it.
- In production, demo billing is off and everyone has the default plan, unless `BILLING_ALLOW_DEMO_IN_PRODUCTION=true` is set for a deployment that exists only as a public demo.
- With `BILLING_PROVIDER=stripe`, demo rows are ignored and the demo routes return 404.
- Billing records say Simulation and carry a DEMO watermark. They aren't invoices.

## Stripe integration

The integration uses Laravel Cashier 16 with Stripe Checkout, the Customer Portal, and Stripe-hosted invoices. Card details never reach this application.

Access comes only from local subscription rows written by signed webhooks or Stripe's own API responses. The Checkout success page only shows a message.

Prices are looked up on the server from `STRIPE_*_MONTHLY_PRICE_ID`. Anything a browser submits is ignored.

Upgrades and downgrades use Cashier's `swap()`, which applies immediately with Stripe's default proration.

### Environment variables

| Variable | Purpose |
| --- | --- |
| `BILLING_PROVIDER` | `demo` or `stripe` |
| `BILLING_MODE` | `test` or `live`, with the stripe provider |
| `BILLING_ALLOW_LIVE` | Must be `true` for live mode |
| `BILLING_ALLOW_DEMO_IN_PRODUCTION` | Only for isolated public demos |
| `BILLING_DEFAULT_PLAN` | Plan for anyone without a subscription |
| `BILLING_PAST_DUE_GRACE_DAYS` | Days of access after a failed payment |
| `STRIPE_KEY`, `STRIPE_SECRET` | Stripe API keys |
| `STRIPE_WEBHOOK_SECRET` | Webhook signing secret. Webhooks are refused without it |
| `STRIPE_STARTER_MONTHLY_PRICE_ID`, `STRIPE_PRO_MONTHLY_PRICE_ID`, `STRIPE_AGENCY_MONTHLY_PRICE_ID` | Server-side price mapping |
| `CASHIER_CURRENCY` | Defaults to `eur` |

The secret key type must match `BILLING_MODE`. Live keys in test mode, or test keys in live mode, disable billing actions.

### Stripe Test Mode setup

1. In a Stripe sandbox, create three products with monthly recurring prices in euros.
2. Copy the test keys and price IDs into `.env`, and set `BILLING_PROVIDER=stripe` and `BILLING_MODE=test`.
3. Enable the Customer Portal in the Stripe Dashboard.
4. Forward webhooks and copy the printed secret into `STRIPE_WEBHOOK_SECRET`:

```bash
stripe listen --forward-to https://ai-product-studio.test/stripe/webhook
```

5. Run `php artisan config:clear`, then upgrade with a Stripe test card.

## Webhooks

The endpoint is `POST /stripe/webhook`. Enable these events: `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.updated`, `customer.deleted`, `payment_method.automatically_updated`, `invoice.payment_action_required` and `invoice.payment_succeeded`.

- **Signatures** are verified by Cashier. Cashier skips verification when no secret is set, so this application refuses webhooks in that case.
- **Duplicates** are detected by Stripe event ID.
- **Ordering:** a strictly older event for a subscription is ignored, and a deleted subscription can't be revived. Stripe's timestamps have one-second precision, so two events in the same second are applied in arrival order.
- **Reconciliation:** the daily `billing:reconcile` command re-reads open subscriptions from Stripe, in case an event was missed. It needs the scheduler.
- **Failures** release the event ID, so Stripe's retry runs it again.
- Only identifiers are stored. Payloads and secrets are never logged.

## REST API

Tokens and plans are both checked. A token on a plan without `api.access` gets `403 plan_feature_unavailable`. `GET /api/v1/me` stays open on every plan and reports the plan and usage, without billing identifiers.

The monthly allowance answers `429 monthly_limit_reached` with `retry_after`, and the product limit answers `403 plan_limit_reached`.

## Security

- Prices and provider come from the server.
- Card data is only entered on Stripe's hosted pages.
- Billing changes need a signed-in user, a CSRF token and an explicit confirmation.
- Invoices and demo records can only be opened by their owner.
- Stripe error text isn't shown or logged.
- `stripe_id` and card details are hidden from model serialization.

## Local development

```bash
php artisan migrate
```

```bash
php artisan queue:work
```

The tests use in-memory SQLite and fake Gemini and Stripe at the network layer:

```bash
php artisan test tests/Feature/Billing
```

`phpunit.xml` sets `BILLING_DEFAULT_PLAN=agency` so that older feature tests can reach paid features. Billing tests set the real default themselves.

## Known limitations

- Real Stripe processing is unverified, as stated above.
- Plan changes through Stripe apply immediately. Downgrading at the end of the period would need Subscription Schedules.
- Prices on the pricing page come from configuration and must be kept in line with Stripe.
- The usage period is a calendar month and isn't aligned to the invoice date.
- Past-due access is measured from when the first related webhook arrives.
- There is no admin interface. Plans are changed in configuration.
- Shopify and agency features aren't implemented.

## Production deployment checklist

- [ ] Complete a full Stripe Test Mode run first, including webhooks, a failed payment and cancellation
- [ ] Review the plan prices and limits
- [ ] Create live prices and set the live price IDs
- [ ] Set `BILLING_PROVIDER=stripe`, `BILLING_MODE=live` and `BILLING_ALLOW_LIVE=true` deliberately
- [ ] Register the HTTPS webhook endpoint and set its secret
- [ ] Run `php artisan migrate`
- [ ] Run `queue:work` under a process manager, and the scheduler
- [ ] Confirm `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Check that `/billing` shows Stripe Live Mode
