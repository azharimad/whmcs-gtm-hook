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
- Accepts every container ID flavour Google now hands out (`GTM-`, `GT-`, `AW-`, `G-`, `DC-`), including containers auto-created by the Google tag → Tag Manager unification
- Format-validated IDs, and placeholder IDs are treated as "not configured", so the file is safe to deploy before your IDs exist

## Which ID is which

| Prefix | What it is | Where you get it | Goes into |
|---|---|---|---|
| `GTM-XXXXXXX` | Full Tag Manager container, all tag types allowed | GTM → **Admin → Install Google Tag Manager** | `HM_GTM_CONTAINER_ID` |
| `GT-XXXXXXXX` | Google tag (one tag, multiple destinations: GA4 + Ads) — also a Google-only container ID | GA4/Ads → **Admin → Google tag** | `HM_GTM_CONTAINER_ID` (container) or `HM_GOOGLE_TAG_ID` (gtag.js) |
| `AW-XXXXXXXXXX` | Google Ads conversion ID | Google Ads → **Goals → Conversions** | same as above |
| `G-XXXXXXXXXX` | GA4 Measurement ID (data stream) | GA4 → **Admin → Data Streams** | `HM_GA4_MEASUREMENT_ID` |
| `UA-XXXXX-X` | Universal Analytics (sunset 2023) | — | not supported |

`AW-` alone does **not** send data to Analytics. If the only IDs you have are `AW-…` and `GT-…`, prefer `GT-…` — it is the unified tag and forwards events to every destination attached to it.

## Google tag → Tag Manager unification (what changed in 2026)

Google upgraded standalone Google tags into full Tag Manager containers. Those containers show up with a **product-prefixed ID** (`AW-…`, `GT-…`) rather than `GTM-…`, and a banner: *"Google tag has been upgraded to a Tag Manager container."*

The prefix of the ID you load decides what the container may run ([Google's rule](https://support.google.com/tagmanager/answer/17070049)):

| ID used to load | Allowed tags |
|---|---|
| `GTM-…` | Everything: Google tags, third-party tags, custom HTML/JS |
| `GT-…` / `AW-…` / `G-…` | **Google-only** — no third-party or custom scripts |

So: if your container list only offers `AW-…` and `GT-…`, you can track GA4 + Ads fine, but you cannot add third-party tags or custom HTML until you create a real `GTM-…` container (**Admin → Create Container**) and load that ID instead.

This hook handles both: it loads whatever container ID you give it through `gtm.js`, and exposes `hm_restricted` in the data layer so you can see which mode the page is in.

## Making the container unrestricted (stop being Google-only)

The restriction comes from the ID prefix, not from a setting you can toggle. To run third-party tags you need a container loaded with a `GTM-` ID:

1. Open [tagmanager.google.com](https://tagmanager.google.com/) → pick the account → **Admin → Create Container** (do not use the container that the Google tag upgrade created).
2. Name it (e.g. `Main Website`), platform **Web**, **Create**. The new container gets a `GTM-XXXXXXX` ID.
3. In that container, create your tags: **Google Analytics → Google Tag** (Measurement ID `G-…`, trigger All Pages) and **GA4 Event** `purchase` (Ecommerce: Data Layer, trigger Custom Event `purchase`).
4. **Submit → Publish** the version. Tags do nothing until a version is published.
5. Put the new ID in `HM_GTM_CONTAINER_ID = 'GTM-XXXXXXX'`. The hook then loads the unrestricted container and stops loading the old `GT-`/`AW-` one — never run both.
6. Remove any leftover `gtag.js` / `gtm.js` snippet pasted manually into your theme, otherwise events are counted twice.
7. Verify: GTM **Preview**, then **Tag Assistant** shows the `GTM-` ID; GA4 **DebugView** shows `purchase`.

If you only ever need Google tags (GA4, Ads, Floodlight), the `GT-` container is fine — the restriction only blocks third-party and custom HTML tags.

## Two sites: marketing website + WHMCS

The same container serves both, but the installation differs:

| Site | How the container loads |
|---|---|
| Marketing website (WordPress, static, landing pages) | The container snippet in `<head>` of every page — `gtm.js` + `noscript` from **Admin → Install Google Tag Manager**, or a GTM plugin |
| WHMCS | `gtm.php` in `includes/hooks/` — never the raw snippet |

Rules that prevent double counting:

- One page = one container load. If WHMCS has the hook and a pasted snippet, every pageview and purchase is counted twice.
- A `gtag.js` snippet that loads a `GTM-` ID is not the documented path for containers: use the `gtm.js` snippet with the `noscript` fallback.
- Use one container for both sites, or separate containers per site — both work. Separate containers make per-site permissions and versioning easier.

### Keeping ad attribution across the two hosts

An ad click lands on the marketing site with `gclid`, but the order finishes on WHMCS — a different host. Unless the click ID travels with the link, Ads cannot attribute that purchase.

`snippets/gclid-forward.js` copies `gclid` / `gbraid` / `wbraid` / `dclid` and the `utm_*` parameters from the landing URL onto every link that points at WHMCS (`/cart.php`, `clientarea.php`, `billing.`, `/order`, ...). Install it on the **marketing site only**:

```html
<script src="/assets/gclid-forward.js" defer></script>
```

Adjust `LINK_PATTERNS` to match your own WHMCS URLs. Tested with `node tests/gclid-forward.test.js` (11 assertions).

Also configure **GA4 → Admin → Data Streams → Configure tag settings → Configure your domains** with both hosts, so the `_ga` cookie survives the hop and one session spans both sites.

For Ads, either:

- put the Conversion Tracking tag (`AW-…` + label) in the container and fire it on `purchase` from WHMCS, or
- count a softer conversion (order button click) on the marketing site and treat the WHMCS purchase as a separate, more valuable action.

Do not use both a GTM Ads conversion tag and an imported GA4 conversion for the same action — Ads will count it twice.

## Install

```bash
cp gtm.php /path/to/whmcs/includes/hooks/gtm.php
```

Configure at the top of the file:

```php
const HM_GTM_MODE           = 'auto';
const HM_GTM_CONTAINER_ID   = 'GTM-XXXXXXX';   // or GT-… / AW-… if that is all you have
const HM_GA4_MEASUREMENT_ID = 'G-XXXXXXXXXX';  // used by the gtag.js fallback
const HM_GOOGLE_TAG_ID      = '';              // optional: GT-… / AW-… for gtag.js fallback
```

No template cache flush needed — hooks are read on the next request.

## Modes

| `HM_GTM_MODE` | Behaviour |
|---|---|
| `auto` (default) | Container if `HM_GTM_CONTAINER_ID` is valid (any prefix), otherwise `gtag.js` if a `G-` / `GT-` / `AW-` ID exists, otherwise off |
| `gtm` | Container via `gtm.js` (tags managed inside the container) |
| `gtag` | `gtag.js` loaded directly, no container — the supported path for a plain Google tag |
| `off` | Nothing is rendered |

In `gtag` mode `G-` wins over `GT-`/`AW-`, so an explicit GA4 stream is never silently replaced. Set only one of them to avoid double counting.

## How GTM and GA4 are linked

Before the container loads, the hook pushes a context object into the data layer:

```js
window.dataLayer.push({
  "hm_ga4_id": "G-XXXXXXXXXX",     // HM_GA4_MEASUREMENT_ID
  "hm_google_tag_id": "",          // HM_GOOGLE_TAG_ID
  "hm_container_id": "GTM-XXXXXXX",// container actually loaded
  "hm_restricted": false,          // true when loaded with a product-prefixed ID
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

12 suites / 184 assertions:

| Suite | Checks |
|---|---|
| dummy IDs (auto → off) | nothing rendered at all |
| GTM only | container, ID, noscript, no direct gtag |
| GTM + GA4 linked | container + context exposes `G-` |
| Google tag container `GT-` | loaded as container, `hm_restricted = true` |
| Ads container `AW-` | loaded as container, `hm_restricted = true` |
| GA4 only | `gtag.js` fallback with `G-` |
| Google tag `GT-` (fallback) | `gtag.js` fallback with `GT-` |
| Google Ads `AW-` (fallback) | `gtag.js` fallback with `AW-` |
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
- Product-prefixed containers (`GT-`, `AW-`) are Google-only. Third-party tags need a `GTM-` container.
- The context push happens before the container starts, so `hm_page` / `hm_logged_in` are available to tags on the first page view; they are overwritten on each navigation.

## License

MIT — see [LICENSE](LICENSE).

---

### Ringkasan (ID)

`GTM-` = container penuh (semua tag), `GT-`/`AW-` = container hasil upgrade Google tag yang cuma boleh tag Google, `G-` = Measurement ID GA4. Sejak 2026 Google meng-upgrade Google tag jadi container, jadi ID container bisa berprefix produk — hook ini menerima semuanya lewat `gtm.js`, dan menandai `hm_restricted = true` kalau prefix-nya bukan `GTM-`. Event `purchase` mengikuti bentuk output per mode, plus consent mode, skip staff, anti dobel saat refresh. Tes lokal: `php tests/run.php` (12 suite, 184 assertion).