# Nova Poshta Premium for OpenCart 4.x

Nova Poshta shipping integration for OpenCart 4.x merchants. Free download with optional Pro license that unlocks premium automation.

**Status:** v1.2.1 — production. Real license server (CatCode), `product_slug` enforcement, all premium features properly gated. Checkout block appearance is themeable (accent colour, corner radius, light/dark/auto) and renders cleanly on the stock OpenCart `basic` theme.

## Free features (no license required)

- OpenCart 4.x native (PSR-namespaced, Twig, events API)
- Encrypted API key at rest (XOR + per-install secret derivation)
- Test Connection button (live NP API ping)
- Sender warehouse selection with city autocomplete
- Live rate preview (real `InternetDocument.getDocumentPrice`)
- **Storefront checkout picker** injected on `</body>` — customer chooses NP city + warehouse without leaving the checkout
- Per-cart `getQuote()` calls NP API with cart weight + value
- Auto-shipment recording on order create (event `addOrder/after`)
- Shipments dashboard in admin (last 200 with status badges)
- Automatic TTN creation on order status reaching the configured trigger
- City + warehouse cache with weekly background sync cron
- **Themeable checkout block** — accent colour, corner radius (0–28px) and Light / Dark / Auto theme configurable in admin (Appearance section); `Auto` inherits the storefront's Bootstrap variables
- uk-ua + en-gb language files
- Setup / Re-install button reruns DB+events+cron registration idempotently

## Pro features (require valid license)

License key purchased separately from https://catcode.com.ua/modules/opencart-nova-poshta-premium — **₴2 990 per year**, or a longer term at a discount (2–5 years, up to −25% per year). Updates and support are included for the whole term; when it ends the premium features lock and the free tier keeps working.

- **COD reconciliation** — auto-attach `BackwardDelivery` on cash-on-delivery orders; daily payout sync against NP `getDocumentList` (BackwardDeliverySum + MoneyTransferNumber tracked per shipment)
- **Status polling cron** (hourly) — batch `TrackingDocument.getStatusDocuments` keeps shipment dashboard live
- **Return TTN** — one-click return label via `AdditionalServiceGeneral.save` with refusal-of-delivery reason
- **Outbound webhooks** — HMAC-SHA256 signed POST on status change, exponential backoff retry (5/10/20/40 min)
- Multi-warehouse sender (configurable per geo-zone/category)
- 14-day offline grace period — premium features keep working through transient license-server outages

## Compatibility

- OpenCart 4.0.2 – 4.1.x (4.2+ Tasks API not required)
- PHP 8.1+ (PHP 8.5 deprecation warnings defensively `ob_clean`-ed)
- MySQL 5.7+ / MariaDB 10.3+

## License

Proprietary. **₴2 990 per year**, term chosen at checkout (1–5 years, longer terms discounted). No auto-renewal — renewals are always manual. Sold per-domain via catcode.com.ua.

## Install (dev)

1. Upload `build/nova_poshta_premium.ocmod.zip` via Admin → Extensions → Installer.
2. Activate via Admin → Extensions → Extensions → Shipping → Install.
3. Open the extension's Edit page and click **Setup / Re-install** — this creates DB tables and registers events + cron jobs.
4. Paste NP API key, click **Test Connection**, save.
5. Search Sender City → pick Warehouse → save.
6. Set Status, sort order, optional auto-TTN order status trigger.

## Project layout

```
upload/
  install.json
  admin/
    controller/
      shipping/nova_poshta.php
      extension/nova_poshta_premium/shipment.php
    model/...
    view/template/
      shipping/nova_poshta.twig
      extension/nova_poshta_premium/shipment_list.twig
    language/{en-gb,uk-ua}/...
  catalog/
    controller/extension/nova_poshta_premium/
      checkout.php       # AJAX search + picker session state
      events.php         # footerInject, orderAdded, orderHistoryAdded
      cron.php           # pollStatus, dispatchWebhooks, licenseCheck
    model/shipping/nova_poshta.php   # getQuote() — calls NP API with cart weight+value
    view/javascript/nova_poshta_premium/picker.js
  system/library/nova_poshta/
    client.php           # HTTP wrapper, quote(), trackStatus() helpers
    crypto.php           # XOR-based at-rest obfuscation
build/                   # zipped .ocmod.zip output (gitignored)
build.ps1                # Compress-Archive helper
```

## Changelog

### 1.2.4
- Fixed: city search failed with an SQL syntax error on stores running the **PDO** database driver (`DB_DRIVER = pdo`). On that driver OpenCart's `escape()` returns a bound placeholder rather than an escaped string, so appending a `%` wildcard inside the quotes produced `LIKE :0%`. Wildcards now go through `escape()` as part of the value, which is correct on both `mysqli` and `pdo`. Warehouse lookup was unaffected, which is why the failure looked like an API key problem.

### 1.2.3
- Fixed: city search returned "nothing found" on hosts without the PHP `mbstring` extension. `mb_strtolower` / `mb_substr` are now called only when available, with UTF-8 safe fallbacks — warehouse lookup was unaffected, which made the failure look like an API key problem.

### 1.2.1
- Verified clean rendering on the stock OpenCart 4.1.x `basic` theme (default appearance, `Auto` block theme).
- Repository synced to the production codebase (git was previously lagging at 1.1.0 while 1.2.x shipped to the marketplace).
- Docs reconciled with the implemented feature set.

### 1.2.0
- Themeable checkout block: configurable accent colour, corner radius (0–28px) and Light / Dark / Auto theme (admin → Appearance). `picker.js` is driven by `--np-*` CSS variables; `Auto` inherits the site's `--bs-*` palette.

### 1.1.0
- Real license server (CatCode) with `product_slug` enforcement and 14-day offline grace; all premium features properly gated.

## Implemented since early dev

Sender Counterparty + Contact Person config, HMAC-signed license verify, city/warehouse caching with weekly cron sync, COD reconciliation, and one-click return labels are all wired up (see Free / Pro feature lists above).

## Build

```powershell
.\build.ps1
# Produces build/nova_poshta_premium.ocmod.zip ready for marketplace upload.
```
