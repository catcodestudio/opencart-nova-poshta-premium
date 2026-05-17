# Nova Poshta Premium for OpenCart 4.x

Premium Nova Poshta shipping integration for OpenCart 4.x merchants.

**Status:** v0.7 — full MVP feature set implemented. Pending: real license server, sender Counterparty configuration for full TTN auto-creation.

## Features

- OpenCart 4.x native (PSR-namespaced, Twig, events API)
- Encrypted API key at rest (XOR + per-install secret derivation)
- Test Connection button (live NP API ping)
- Sender warehouse selection with city autocomplete
- Live rate preview (real `InternetDocument.getDocumentPrice`)
- **Storefront checkout picker** injected on `</body>` — customer chooses NP city + warehouse without leaving the checkout
- Per-cart `getQuote()` calls NP API with cart weight + value
- **Auto-shipment recording** on order create (event `addOrder/after`)
- **Shipments dashboard** in admin (last 200 with status badges)
- **Status polling cron** (hourly) — batch `TrackingDocument.getStatusDocuments`
- **Outbound webhooks** on status change (HMAC-SHA256 signed, exponential backoff retry)
- License key field with stub server verification (NPP-XXXX-XXXX-XXXX format)
- uk-ua + en-gb language files
- Setup / Re-install button reruns DB+events+cron registration idempotently

## Compatibility

- OpenCart 4.0.2 – 4.1.x (4.2+ Tasks API not required)
- PHP 8.1+ (PHP 8.5 deprecation warnings defensively `ob_clean`-ed)
- MySQL 5.7+ / MariaDB 10.3+

## License

Proprietary. €99 one-time + €39/yr maintenance, sold per-domain via vendor site.

## Install (dev)

1. Upload `build/nova-poshta-premium.ocmod.zip` via Admin → Extensions → Installer.
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

## Deferred / known TODO

- **Sender Counterparty + Contact Person config** — required by NP `InternetDocument.save` to actually mint TTNs. Stubbed in `events.orderHistoryAdded` — status text marks it.
- **Real license server** — `licenseCheck` accepts any well-formed `NPP-XXXX-XXXX-XXXX` for dev. Production needs HMAC-signed verify endpoint.
- **City/warehouse caching** — currently every search/load hits NP live. Should mirror into `np_cities`/`np_warehouses` tables with weekly cron sync (table names reserved in schema PDF, not yet implemented).
- **COD reconciliation dashboard** — table column exists, no UI yet.
- **Return labels** — `AdditionalService.save` not wired.

## Build

```powershell
.\build.ps1
# Produces build/nova-poshta-premium.ocmod.zip ready for marketplace upload.
```
