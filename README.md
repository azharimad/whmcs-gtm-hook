# WHMCS Google Tag Manager + GA4 Hook

One update-safe hook file that wires **Google Tag Manager** and **GA4** together for WHMCS — no template edits, no addon module, survives theme changes and WHMCS upgrades.

Both IDs live in one place (this file), and the GA4 Measurement ID is pushed into the data layer, so GTM reads it as a variable instead of you pasting it into every tag.

- Container script in `<head>` via `ClientAreaHeadOutput`
- `noscript` iframe at the top of `<body>` via `ClientAreaHeaderOutput`
- GA4 `purchase` event with `transaction_id`, `value`, `currency`, `items` via `ShoppingCartCheckoutCompletePage`
- Consent Mode v2 defaults
- Staff sessions (including *Login as Owner*) are never tracked
- Server-side de-duplication: refreshing the order-complete page does not push a second purchase
- Automatic fallback to `gtag.js` when no container is configured
- Silent no-op until a valid ID is present

## Install

```bash
cp gtm.php /path/to/whmcs/includes/hooks/gtm.php
```

Set the IDs at the top of the file:

```php
const HM_GTM_MODE           = 'auto';
const HM_GTM_CONTAINER_ID   = 'GTM-XXXXXXX';   // <- your container
const HM_GA4_MEASUREMENT_ID = 'G-XXXXXXXXXX';  // <- your GA4 measurement ID
```

No template cache flush needed — hooks are read on the next request.

## Modes

| `HM_GTM_MODE` | Behaviour |
|---|---|
| `auto` (default) | GTM if a valid container ID exists, otherwise `gtag.js` if a valid Measurement ID exists, otherwise off |
| `gtm` | GTM container only. GA4 lives as a tag inside the container. |
| `gtag` | `gtag.js` loaded directly, GTM bypassed. For a quick GA4-only setup. |
| `off` | Nothing is rendered. |

Dummy placeholders (`GTM-XXXXXXX`, `G-XXXXXXXXXX`) are treated as "not configured", so the file is safe to deploy before your IDs exist. Format is validated (`GTM-[A-Z0-9]{4,}`, `G-[A-Z0-9]{4,}`).

## How GTM and GA4 are linked

Before the container loads, the hook pushes a context object into the data layer:

```js
window.dataLayer.push({
  "hm_ga4_id": "G-XXXXXXXXXX",   // from HM_GA4_MEASUREMENT_ID
  "hm_channel": "gtm",           // gtm | gtag | off
  "hm_page": "viewinvoice",      // WHMCS template file
  "hm_logged_in": true
});
```

In GTM:

1. **Variables → New → Data Layer Variable**, name `DL - hm_ga4_id`, Data Layer Variable Name `hm_ga4_id`
2. On the **Google tag**, set Measurement ID to `{{DL - hm_ga4_id}}`

Now the Measurement ID exists once — in PHP — and every tag in the container shares it. Optional extras: `DL - hm_page` and `DL - hm_logged_in` as GA4 event parameters, or as trigger conditions (e.g. fire a tag only for `viewinvoice`).

## Required GTM tags (mode `gtm`)

**Google tag (pageviews)**

1. Tag type **Google tag**, Measurement ID `{{DL - hm_ga4_id}}`
2. Trigger **All Pages**

**Purchase event**

1. Tag type **GA4 Event**, event name `purchase`, Measurement ID `{{DL - hm_ga4_id}}`
2. *More Settings → Ecommerce → Send Ecommerce data → Data Layer*
3. Trigger **Custom Event** where event name equals `purchase`

Only one pageview source should exist: do **not** activate WHMCS' built-in Google Analytics addon with the same Measurement ID at the same time, or pageviews are counted twice. In `gtag` mode the hook loads GA4 itself — do not add a Google tag in GTM for the same property.

## Payload

`gtm` mode pushes the data-layer ecommerce object:

```json
{
  "event": "purchase",
  "invoice_paid": true,
  "ecommerce": {
    "transaction_id": "1234567890",
    "value": 10.00,
    "currency": "IDR",
    "items": [
      { "item_name": "Shared Hosting Basic", "item_category": "Hosting", "price": 5,   "quantity": 1 },
      { "item_name": "SSL Certificate",      "item_category": "Addon",   "price": 2.5, "quantity": 2 }
    ]
  }
}
```

`gtag` mode emits the native GA4 shape instead: `gtag('event', 'purchase', {...})` with `transaction_id`, `value`, `currency`, `invoice_paid` and `items` as flat parameters.

- `value` = Σ (price × quantity), excluding tax and shipping, per Google's definition.
- `transaction_id` is the WHMCS order number, which GA4 uses to de-duplicate.
- `invoice_paid` is `false` for offline gateways (bank transfer, mail-in payment). Add a trigger condition on it if you only want paid conversions.

## Testing

Runs without a WHMCS installation (PHP 8.1+), stubbing `add_hook()`, `localAPI()` and `CurrentUser`. It builds a temporary copy of the hook per scenario:

```bash
php tests/run.php
```

```
== dummy IDs (auto -> off) ==
  mode resolves to 'off'                     PASS
  head renders nothing                       PASS

== GTM + GA4 linked ==
  mode resolves to 'gtm'                     PASS
  consent default pushed                     PASS
  context page type pushed                   PASS
  context logged-in flag pushed              PASS
  GTM container in head                      PASS
  noscript iframe in body                    PASS
  purchase event emitted                     PASS
  reload does not double-push                PASS
  transaction_id = order number              PASS
  currency from client record                PASS
  value = 5 + 2.5*2                          PASS
  two line items mapped                      PASS

== GA4 only (gtag fallback) ==
  mode resolves to 'gtag'                    PASS
  gtag.js loaded directly                    PASS
  gtag uses native event shape               PASS
  ...
```

Suites: dummy IDs, GTM only, GTM + GA4 linked, GA4-only fallback, `mode=off`, staff session, masquerading admin.

Live checks: GTM Preview / Tag Assistant, GA4 DebugView, or in the browser console:

```js
window.dataLayer.filter(e => e.event === 'purchase')
```

## Known limitations

- **Renewals are not purchases.** They are invoices, not cart orders, so they never reach `ShoppingCartCheckoutCompletePage`. For renewal revenue in GA4 you need `OrderPaid` plus the Measurement Protocol (server-side).
- Browser-side tracking cannot see payments that never return to WHMCS (e.g. some offline gateways).
- Custom themes that drop `{$headeroutput}` / `{$footeroutput}` will lose the body output; the head hook is the safe one.
- Consent defaults are static. To react to a banner, push `gtag('consent', 'update', {...})` from your consent tool inside the container.
- The context push happens before the container starts, so `hm_page` / `hm_logged_in` are available to tags on the first page view; they are overwritten on each navigation.

## License

MIT — see [LICENSE](LICENSE).

---

### Ringkasan (ID)

Satu file hook untuk GTM + GA4 di WHMCS. Dua ID disimpan di satu tempat; Measurement ID ikut di-push ke dataLayer sebagai `hm_ga4_id`, jadi tag di GTM cukup pakai `{{DL - hm_ga4_id}}` — tidak perlu tempel ID berulang. Mode `auto` otomatis pilih GTM kalau container valid, atau `gtag.js` kalau cuma ada Measurement ID. Event `purchase` menyesuaikan bentuk output per mode (objek ecommerce untuk GTM, event native untuk gtag.js), plus consent mode, skip staff, dan anti dobel saat refresh. Tes lokal: `php tests/run.php`.