# WHMCS Google Tag Manager + GA4 Hook

One update-safe hook file that wires **Google Tag Manager** and **GA4** together for WHMCS — no template edits, no addon module, survives theme changes and WHMCS upgrades.

IDs live in one place (this file), and the GA4 Measurement ID is pushed into the data layer, so GTM reads it as a variable instead of you pasting it into every tag.

- Container script in `<head>` via `ClientAreaHeadOutput`
- `noscript` iframe at the top of `<body>` via `ClientAreaHeaderOutput`
- GA4 `purchase` event with `transaction_id`, `value`, `currency` and `items` via `ShoppingCartCheckoutCompletePage`
- Consent Mode v2 defaults (works with GTM's per-tag consent overview)
- Staff sessions (including *Login as Owner*) are never tracked
- Server-side de-duplication: refreshing the order-complete page does not push a second purchase
- Automatic `gtag.js` fallback when no container is configured — accepts `G-`, `GT-` or `AW-`
- Format-validated IDs, and placeholder IDs are treated as "not configured", so the file is safe to deploy before your IDs exist

## Which ID is which

| Prefix | What it is | Where you get it | Goes into |
|---|---|---|---|
| `GTM-XXXXXXX` | Tag Manager container | GTM → **Admin → Install Google Tag Manager** | `HM_GTM_CONTAINER_ID` |
| `G-XXXXXXXXXX` | GA4 Measurement ID (data stream) | GA4 → **Admin → Data Streams →** your web stream | `HM_GA4_MEASUREMENT_ID` |
| `GT-XXXXXXXX` | Google tag (one tag, multiple destinations: GA4 + Ads) | GA4/Ads → **Admin → Google tag** / GTM container settings | `HM_GOOGLE_TAG_ID` |
| `AW-XXXXXXXXXX` | Google Ads conversion ID | Google Ads → **Goals → Conversions →** tag setup | `HM_GOOGLE_TAG_ID` |
| `GTM-` ≠ `GT-` | Different products | — | — |
| `UA-XXXXX-X` | Universal Analytics (sunset in 2023) | — | not supported |

`AW-` alone does **not** send data to Analytics. If the only IDs you have are `AW-…` and `GT-…`, you still need a GA4 property (`G-…`) for Analytics reports, or use the `GT-` tag with a GA4 destination attached.

## Install

```bash
cp gtm.php /path/to/whmcs/includes/hooks/gtm.php
```

Configure at the top of the file:

```php
const HM_GTM_MODE           = 'auto';
const HM_GTM_CONTAINER_ID   = 'GTM-XXXXXXX';   // <- container
const HM_GA4_MEASUREMENT_ID = 'G-XXXXXXXXXX';  // <- GA4 measurement ID
const HM_GOOGLE_TAG_ID      = '';              // optional: GT-... / AW-...
```

No template cache flush needed — hooks are read on the next request.

## Modes

| `HM_GTM_MODE` | Behaviour |
|---|---|
| `auto` (default) | GTM if a valid container exists, otherwise `gtag.js` if a `G-` / `GT-` / `AW-` ID exists, otherwise off |
| `gtm` | GTM container only. GA4 lives as a tag inside the container. |
| `gtag` | `gtag.js` loaded directly, GTM bypassed. Quick GA4-only (or Ads-only) setup. |
| `off` | Nothing is rendered. |

In `gtag` mode `G-` wins over `GT-`/`AW-`, so an explicit GA4 stream is never silently replaced. Set only one of them to avoid double counting.

## How GTM and GA4 are linked

Before the container loads, the hook pushes a context object into the data layer:

```js
window.dataLayer.push({
  "hm_ga4_id": "G-XXXXXXXXXX",     // HM_GA4_MEASUREMENT_ID
  "hm_google_tag_id": "",          // HM_GOOGLE_TAG_ID (GT- / AW-)
  "hm_channel": "gtm",             // gtm | gtag
  "hm_page": "viewinvoice",        // WHMCS template file
  "hm_logged_in": true
});
```

In GTM:

1. **Variables → New → Data Layer Variable**, name `DL - hm_ga4_id`, Data Layer Variable Name `hm_ga4_id`
2. On every GA4 tag, set Measurement ID to `{{DL - hm_ga4_id}}`

Now the Measurement ID lives once — in PHP — and every tag in the container shares it. Optional extras: use `DL - hm_page` and `DL - hm_logged_in` as GA4 event parameters, or as trigger conditions (e.g. fire only on `viewinvoice`).

## Required GTM tags (mode `gtm`)

**Google tag (pageviews)**

1. Tag type: **Google Analytics → Google Tag** (not Google Ads, not Gallery templates)
2. Measurement ID `{{DL - hm_ga4_id}}`
3. Trigger **All Pages**

**Purchase event**

1. Tag type: **Google Analytics → GA4 Event**, event name `purchase`, Measurement ID `{{DL - hm_ga4_id}}`
2. *More Settings → Ecommerce → Send Ecommerce data → Data Layer*
3. Trigger **Custom Event** where event name equals `purchase`

Only one pageview source should exist: do **not** activate WHMCS' built-in Google Analytics addon with the same Measurement ID, and do not paste a `gtag.js` snippet into `header.tpl` while the container runs. In `gtag` mode the hook loads GA4 itself — do not add a Google tag in GTM for the same property.

Optional: tick **Container Settings → Enable consent overview** so each tag's consent requirements are visible next to the defaults this hook pushes.

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

10 suites / 138 assertions:

| Suite | Checks |
|---|---|
| dummy IDs (auto → off) | nothing rendered at all |
| GTM only | container, ID, noscript, no direct gtag |
| GTM + GA4 linked | container + context exposes `G-` |
| GA4 only | `gtag.js` fallback with `G-` |
| Google tag `GT-` | `gtag.js` fallback with `GT-` |
| Google Ads `AW-` | `gtag.js` fallback with `AW-` |
| GA4 wins over `GT-` | explicit `G-` preferred |
| `mode=off` | nothing rendered despite valid IDs |
| staff session | nothing rendered |
| masquerading admin | nothing rendered |

Every rendering suite also asserts consent default, context values, purchase payload (`transaction_id`, `currency`, `value`, `items`, `invoice_paid`) and refresh de-duplication.

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
- `GT-` / `AW-` fallback loads Google's `gtag.js` directly. If that Google tag has a GA4 destination attached, everything lands in GA4 under that property — but the `G-` Measurement ID field in the context stays empty unless you also set `HM_GA4_MEASUREMENT_ID`.

## License

MIT — see [LICENSE](LICENSE).

---

### Ringkasan (ID)

Satu file hook untuk GTM + GA4 di WHMCS. `GTM-` = container, `G-` = GA4 measurement ID, `GT-`/`AW-` = Google tag payung (opsional, dipakai saat fallback `gtag.js`). Measurement ID ikut di-push ke dataLayer sebagai `hm_ga4_id`, jadi tag di GTM cukup pakai `{{DL - hm_ga4_id}}`. Mode `auto` pilih GTM kalau container valid, atau `gtag.js` kalau cuma ada `G-`/`GT-`/`AW-`. Event `purchase` menyesuaikan bentuk output per mode, plus consent mode, skip staff, dan anti dobel saat refresh. Tes lokal: `php tests/run.php` (10 suite, 138 assertion).