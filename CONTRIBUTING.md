# Contributing

Thanks for taking an interest. Set up the project using the [installation guide](docs/setup/INSTALLATION.md).

## Before opening a pull request

```bash
php artisan test
```

```bash
vendor/bin/pint --test app routes tests database config
```

```bash
npm run build
```

## Project conventions

- Keep business rules in services under `app/Services`, so the web app, API and jobs share them.
- Put new plan rules in `config/billing.php` and `PlanEntitlementService`, not in controllers.
- Every query for a user's data starts from that user.
- New API routes need an ability, an OpenAPI entry, and a README entry. A test checks the first two match.
- Database changes are additive migrations.

## Tests

- Tests run on in-memory SQLite only.
- Never call a real Gemini, WooCommerce or Stripe service from a test. Use the fakes in `tests/Concerns` and `tests/Support`.
- Anything involving concurrency also needs checking on MySQL, because SQLite doesn't reproduce its locking.

## Secrets and honesty

- Never commit `.env`, keys, tokens or real customer data.
- Screenshots use fictional data only.
- Don't describe an integration as verified unless it has run against the real service. Simulated behavior must be labeled.

## Security issues

Please don't open a public issue for a vulnerability. Contact the repository owner privately through GitHub.
