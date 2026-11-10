# Troubleshooting

| Problem | What to check |
| --- | --- |
| `php` isn't recognized on Windows | Use the Terminal from Laragon, or add the PHP 8.3 folder to your path. |
| Composer reports missing PHP extensions | Enable them in `php.ini`. The list is in the [installation guide](INSTALLATION.md#requirements). |
| Styles are missing, or a Vite manifest error appears | Run `npm run build`. |
| Bulk generation or an API job stays queued | Start `php artisan queue:work`. |
| "AI generation isn't set up yet" | Set `GEMINI_API_KEY` and `GEMINI_MODEL`, then run `php artisan config:clear`. |
| Gemini says the model isn't available | Run `php artisan ai:gemini-check`, and change `GEMINI_MODEL` to a model your key can use. |
| A page sends you to Billing | Your plan doesn't include that action. In Demo Billing you can simulate an upgrade. |
| AI requests refused with a monthly limit | The plan's allowance is used up. Demo Billing's reset clears only simulated usage. |
| A local `.test` store is refused | Real stores must be public HTTPS addresses. Use the demo store locally. |
| API returns 401 with a valid token | Check that the web server forwards the `Authorization` header, and that the token hasn't expired. |
| API returns 403 `plan_feature_unavailable` | The account's plan doesn't include the REST API. |
| Config changes have no effect | Run `php artisan config:clear`. |
