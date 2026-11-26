# WHMCS Google Tag Manager + GA4 Hook

A single, update-safe hook file that adds **Google Tag Manager** and a **GA4 `purchase` event** to WHMCS — no template edits, no addon module, survives theme changes and WHMCS upgrades.

- Container script in `<head>` via `ClientAreaHeadOutput`
- `noscript` iframe at the top of `<body>` via `ClientAreaHeaderOutput`
- GA4 `purchase` event with `transaction_id`, `value`, `currency` and `items` via `ShoppingCartCheckoutCompletePage`
- GA4 Consent Mode v2 defaults
- Staff sessions (including *Login as Owner*) are never tracked
- Server-side de-duplication: refreshing the order-complete page does not push a second purchase
- Silent no-op until you set a real container ID

## Install

```bash
cp gtm.php /path/to/whmcs/includes/hooks/gtm.php
```

Then edit the container ID at the top of the file:

```php
const HM_GTM_CONTAINER_ID = 'GTM-XXXXXXX';   // <- your container
```

That's it. No template cache flush needed — hooks are read on the next request.

## Configuration

| Constant | Default | Purpose |
|---|---|---|
| `HM_GTM_CONTAINER_ID` | `GTM-XXXXXXX` | Your GTM container ID. While dummy, the hook renders nothing. |
| `HM_GA4_MEASUREMENT_ID` | `G-XXXXXXXXXX` | Documentation / validation only — the GA4 tag lives in GTM |
| `HM_GTM_SEND_PURCHASE` | `true` | Push the `purchase` event on the order-complete page |
| `HM_GTM_CONSENT_DEFAULT` | `granted` | Consent Mode v2 default (`granted` or `denied`) |
| `HM_GTM_SKIP_STAFF` | `true` | Skip staff and masquerading-admin sessions |

## Required GTM configuration

**Google tag (pageviews)**

1. Tag type **Google tag**, Measurement ID `G-XXXXXXXXXX`
2. Trigger **All Pages**

**Purchase event**

1. Tag type **GA4 Event**, event name `purchase`, same Measurement ID
2. *More Settings → Ecommerce → Send Ecommerce data → Data Layer*
3. Trigger **Custom Event** where event name equals `purchase`

Only one pageview source should exist: do **not** activate WHMCS' built-in Google Analytics addon with the same Measurement ID at the same time, or pageviews are counted twice.

## Payload

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

- `value` = Σ (price × quantity), excluding tax and shipping, per Google's definition.
- `transaction_id` is the WHMCS order number, which GA4 uses to de-duplicate.
- `invoice_paid` is `false` for offline gateways (bank transfer, mail-in payment). Add a trigger condition on it if you only want paid conversions.

## Testing

Runs without a WHMCS installation (PHP 8.1+), stubbing `add_hook()`, `localAPI()` and `CurrentUser`:

```bash
php tests/run.php
```

```
== dummy container ID ==
  3 hooks registered                         PASS
  dummy ID renders nothing (head)            PASS
  dummy ID renders nothing (noscript)        PASS
  dummy ID renders no purchase               PASS

== real container ID ==
  GTM container in head                      PASS
  container ID passed                        PASS
  consent default pushed                     PASS
  noscript iframe in body                    PASS
  purchase event pushed                      PASS
  reload does not double-push                PASS
  event name = purchase                      PASS
  transaction_id = order number              PASS
  currency from client record                PASS
  value = 5 + 2.5*2                          PASS
  two line items mapped                      PASS
  invoice_paid flag forwarded                PASS

== staff logged into clientarea ==
  head empty for staff                       PASS
  ...

All suites passed.
```

Live checks: GTM Preview / Tag Assistant, GA4 DebugView, or in the browser console:

```js
window.dataLayer.filter(e => e.event === 'purchase')
```

## Known limitations

- **Renewals are not purchases.** They are invoices, not cart orders, so they never reach `ShoppingCartCheckoutCompletePage`. For renewal revenue in GA4 you need `OrderPaid` plus the Measurement Protocol (server-side).
- Browser-side tracking cannot see payments that never return to WHMCS (e.g. some offline gateways).
- Custom themes that drop `{$headeroutput}` / `{$footeroutput}` will lose the body/footer output; the head hook is the safe one.
- Consent defaults are static. To react to a banner, push `gtag('consent', 'update', {...})` from your consent tool inside the container.

## License

MIT — see [LICENSE](LICENSE).

---

### Ringkasan (ID)

Satu file hook untuk GTM + GA4 di WHMCS: container di head, noscript di body, event `purchase` lengkap dengan items dan currency, skip staff, anti dobel saat refresh. Copy `gtm.php` ke `includes/hooks/`, ganti `HM_GTM_CONTAINER_ID`, lalu buat Google tag dan GA4 Event `purchase` di dalam GTM. Tes lokal: `php tests/run.php`.