# Receivify for Magento 2 / Adobe Commerce

[![CI](https://github.com/receivify/receivify-magento/actions/workflows/ci.yml/badge.svg)](https://github.com/receivify/receivify-magento/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/receivify/receivify-magento)](https://github.com/receivify/receivify-magento/releases/latest)
[![License: MIT](https://img.shields.io/badge/license-MIT-a3e635.svg)](LICENSE)

Accept Bitcoin, Ethereum, USDT, USDC and other crypto in your Magento 2 / Adobe Commerce store with [Receivify](https://receivify.io).
Customers pay on a hosted checkout, the payment is checked on the blockchain, and the money is forwarded
straight to **your own wallet**, minus a 1% fee. Non-custodial, no ID checks, signed webhooks mark orders paid.

This repository is the full, readable source of the official plugin, so you can audit exactly what runs in your store.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.x
- PHP 7.4, 8.1, 8.2 or 8.3
- A Receivify account (free) with an API key and webhook secret

## Install

1. Download `receivify-magento.zip` from the [latest release](https://github.com/receivify/receivify-magento/releases/latest)
   (or from [receivify.io/plugins/receivify-magento.zip](https://receivify.io/plugins/receivify-magento.zip)).
2. Unzip `receivify-magento.zip` so the module lands at `app/code/Receivify/Payments`.
3. Run `bin/magento module:enable Receivify_Payments && bin/magento setup:upgrade && bin/magento cache:flush`.
4. Go to **Stores → Configuration → Sales → Payment Methods → Receivify**.

## Configure

In your Receivify dashboard create an **API key** (Dashboard → API keys) and copy your **webhook secret**
(Dashboard → Webhooks), then paste both into the plugin settings. The webhook URL (`https://your-store.com/receivify/webhook`) is sent with every invoice automatically, so there is nothing to paste.

## Test before going live

1. In the Receivify dashboard open **Webhooks → Send test** and check your store accepts the signed message.
2. Place one small real order at the coin's minimum (a stablecoin on a cheap network costs a few cents in fees),
   pay it from your own wallet, and watch the order move to paid when the webhook lands.

## Support

- Documentation: https://receivify.io/docs
- Integration guide: https://receivify.io/integrations/magento
- Help: https://receivify.io/support
- Issues with this plugin: [open an issue](https://github.com/receivify/receivify-magento/issues)

## Security

Please report vulnerabilities privately to **security@receivify.io** (see our
[disclosure policy](https://receivify.io/bug-bounty)). Do not open public issues for security problems.

## License

MIT. See [LICENSE](LICENSE).

---

## Detailed guide

Accept Bitcoin & crypto in Magento 2 via Receivify. Non-custodial: funds settle to
your own payout addresses; the store only ever sees a hosted checkout URL and a
signed webhook.

Targets **Magento Open Source 2.4.x** (PHP 7.4 / 8.1+).

## How it works

1. The customer picks **Bitcoin & crypto (Receivify)** at checkout and clicks
   Place Order. Magento creates the order in `pending_payment`.
2. The method renderer sends the browser to `/receivify/checkout/start`. That
   controller creates a **fiat-priced** invoice (`POST /api/v1/invoices` with
   `amount_fiat` + the order currency) with `reference` =
   `m2-<store token>-<increment id>`, then redirects to Receivify's hosted checkout.
3. Receivify POSTs a **signed** webhook to `https://yourstore/receivify/webhook`.
   The controller verifies the HMAC, de-duplicates on `(event, sequence)`, and
   only then invoices and moves the order.

The API key never reaches the browser: the invoice is created server-side, in
step 2, from a controller.

All HTTP and HMAC work is the shared [PHP SDK](../php-sdk/README.md), vendored at
`Lib/Receivify.php`. The module contains no HTTP client and no crypto of its own.

## Install

```bash
# app/code install
mkdir -p <magento-root>/app/code
cp -r Receivify <magento-root>/app/code/

cd <magento-root>
bin/magento module:enable Receivify_Payments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy   # production mode only
bin/magento cache:flush
```

`setup:upgrade` creates the `receivify_webhook_event` table from
`etc/db_schema.xml`.

Then **Stores → Configuration → Sales → Payment Methods → Receivify**:

- **Enabled** → Yes
- **Receivify API URL** — e.g. `https://receivify.io`
- **API key** — your `rcv_live_…` key (stored encrypted)
- **Webhook secret** — your `whsec_…` secret (stored encrypted)
- **Settlement coin** — must be enabled *and* settleable in Receivify admin → Assets
- **New order status** — the status while the payment is outstanding
  (default `pending_payment`)

Finally, register `https://yourstore/receivify/webhook` in Receivify as a webhook
endpoint. The same URL is also sent as the per-invoice `callback_url`, so
payments work even before an account-level endpoint exists.

In your Receivify dashboard open **Webhooks → Send test** to confirm your store accepts the signed message, then place one small real order at the coin's minimum (a stablecoin on a cheap network costs a few cents in fees) and pay it from your own wallet.

## Order-status mapping

| Receivify event | Magento action |
|---|---|
| *(order placed)* | `pending_payment` (configurable) + history comment naming the invoice, amount and address |
| `invoice.confirming` | history comment, no state change (do **not** ship) |
| `invoice.paid`, `invoice.swept`, `sweep.sent` | offline-capture invoice created, order → `processing`, customer notified |
| `invoice.partial`, `invoice.partial_expired` | history comment naming received vs expected base units |
| `invoice.expired`, `invoice.cancelled` | `$order->cancel()` (stock returned) + comment |
| `invoice.failed` | order held (`STATE_HOLDED`) + comment |
| `test`, `forward.*`, anything unknown | 200, nothing changed |

A payment that arrives for an already-cancelled or closed order does **not**
silently reopen it: it adds a comment saying a human is needed.

## Webhook security

- `X-Receivify-Signature: t=<unix>,v1=<hex>` where
  `v1 = HMAC-SHA256(secret, "<t>.<rawBody>")`.
- Verified against the **raw** request body (`$request->getContent()`) — never
  re-encoded JSON — in constant time, with a 300-second replay window.
- A failed verification answers **401** and writes nothing.
- Magento's CSRF form-key check is disabled for this one route via
  `CsrfAwareActionInterface`, as it must be for any machine callback. The HMAC is
  the authentication.
- **Rotation:** put the new secret in *Webhook secret* and the old one in
  *Previous webhook secret*. Receivify signs with the current secret, but the
  previous one stays acceptable until its grace window closes (24h by default),
  so a receiver must accept either. Clear the previous field afterwards.

## Idempotency

- **Outbound:** `reference` and `Idempotency-Key` are both
  `m2-<store token>-<increment_id>` (token in the flag table,
  `receivify_store_token`). Both de-duplicate server-side, so a retried
  checkout can never mint a second invoice.
  The store token is random, created once per install and never changed, so two
  shops on one Receivify account (or a reinstalled shop that restarts its order
  numbers) never collide. Webhooks carrying another shop's token are
  acknowledged and ignored; bare references from older invoices still work.
- **Inbound:** every delivery is claimed in `receivify_webhook_event` keyed on
  `"<event>:<sequence>"` before anything is written. A retry or a manual replay
  answers `200 duplicate` and changes nothing.
- If applying a claimed event throws, the controller releases the claim and
  answers `500`, so Receivify's retry (with backoff, for up to 24 hours) applies
  it again; applying is safe to repeat. A database error while claiming is also
  a `500`, never a false "duplicate".

## Files

```
Receivify/Payments/
  registration.php, composer.json
  etc/module.xml, config.xml, di.xml, db_schema.xml
  etc/adminhtml/system.xml
  etc/frontend/di.xml, routes.xml
  Controller/Checkout/Start.php        creates the invoice, redirects
  Controller/Webhook/Index.php         verifies + applies the callback
  Model/Config.php                     config access + SDK client factory
  Model/EventGuard.php                 (event, sequence) de-duplication
  Model/OrderUpdater.php               event -> order transition
  Model/StoreToken.php                 per-install token for unique invoice references
  Model/Ui/ConfigProvider.php          checkout config
  Model/Adminhtml/Source/Asset.php     settlement-coin dropdown
  Lib/Receivify.php                    the shared PHP SDK (vendored)
  view/frontend/…                      layout, method renderer, template
```

The payment method itself is a `Magento\Payment\Model\Method\Adapter` virtual
type declared in `di.xml` — the supported route since 2.3 — with a config value
handler and no command pool. There is nothing to capture through Magento: the
money moves on-chain and the webhook reports it.

## Not verified

This module has **not** been installed or run against a live Magento instance.
Every PHP file passes `php -l` and the structure follows 2.4 conventions, but the
following have not been exercised:

- `bin/magento setup:upgrade` / `setup:di:compile` completing cleanly (in
  particular, that the `di.xml` virtual types compile and the `db_schema.xml`
  validates against your Magento version),
- the payment method appearing on the checkout page and the Knockout renderer
  binding,
- `restoreQuote()` behaviour on a failed invoice create,
- the offline-capture invoice + `processing` transition and the emails it sends,
- `$request->getContent()` returning the untouched raw body on your stack (there
  is a `php://input` fallback, but neither path has been observed live).

Test on a staging store first: send a test webhook from the dashboard, then take one small real payment before going live.
