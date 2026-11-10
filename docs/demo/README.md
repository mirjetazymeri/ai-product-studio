# Demo guide

You can evaluate the main workflows with no third-party accounts. Register a user, then follow any section below. Demo pages are labeled, and simulated results are never presented as real ones.

A new account is on the Free plan. To try bulk generation, the REST API or higher limits, simulate an upgrade first, as described under [Billing demo](#billing-demo).

## WooCommerce demo

Available on every plan, with no store, keys or queue worker.

1. **Create the demo connection.** Open **Integrations** and choose **Try WooCommerce Demo**.
2. **Import sample products.** Choose **Import Demo Products**, then confirm. One record is deliberately broken, to show how errors are reported.
3. **Generate sample output.** Choose **Generate Sample AI Output**.
4. **Review and approve.** In **Content Review**, tick the drafts and **Replace saved content where it exists**, then choose **Approve selected**. Or open a draft to edit or reject it.
5. **Preview a sync.** In **Linked Products**, select an approved product, choose **Sync Content**, then **Preview changes**.
6. **Run the simulated sync.** Choose **Run simulated synchronization**, then **View simulated product**. The descriptive fields change, and protected fields show **Unchanged**.
7. **Simulate a conflict.** Build another preview and choose **Simulate Remote Change** before confirming. Nothing is overwritten, and **Refresh preview** starts again from the new value.
8. **Simulate an outage.** **Simulate Outage** makes every simulated request answer HTTP 503.
9. **Reset.** **Reset Demo Store** restores the catalog and removes only demo data.

More detail is in the [WooCommerce Demo Mode guide](../integrations/woocommerce-demo-mode.md).

## Shopify

Shopify isn't implemented, so there is no demo. It appears in the app only as Coming Soon.

## Billing demo

Demo Billing is the default, and takes no payments. Open **Billing & Subscription**.

| Try this | How | What happens |
| --- | --- | --- |
| Change plan | Choose **Upgrade** or **Downgrade**, then confirm | Limits and features change at once |
| Reach a limit | **Use up AI allowance** | AI requests are refused without calling Gemini |
| Failed payment | **Simulate payment failure** | Past Due, with access during a grace period |
| Recovery | **Simulate successful retry** | Back to Active |
| Cancellation | **Cancel subscription** | Canceling, with access until the period ends |
| Expiration | **Simulate expiration** | Back to Free limits, with all data kept |
| Start again | **Restore Free plan** or **Reset demo billing** | Simulated records are removed, real usage is kept |

Records are marked Simulation and carry a DEMO watermark. They aren't invoices, and no money moves. Demo Billing is switched off in production unless the server explicitly allows it.

Details are in the [billing guide](../integrations/billing.md).

## Gemini and Sample AI Output

| | Sample AI Output | Real Gemini |
| --- | --- | --- |
| Source | Fixed templates in the application | Google's API |
| Needs a key | No | Yes |
| Uses tokens or quota | No | Yes |
| Same input, same output | Yes | No |
| Available for | WooCommerce demo products | All products |
| Label in the app | Sample AI Output | Real Gemini on demo products |

Sample text is an example for trying the workflow. It's never described as Gemini's output, including after approval. A real Gemini request for a demo product needs an explicit confirmation.

Without a key, AI features explain that they aren't set up, and everything else keeps working.

## REST API locally

Simulate a Pro plan, then create a token under **Settings, API Access**. The [examples](../api/examples.md) show each request. Generation and exports need `php artisan queue:work`.
