# Nova Poshta Premium for OpenCart 4.x

## Title (60 chars max)
Nova Poshta Premium — OC 4.x native shipping + TTN automation

## Short description (160 chars max)
Native OC 4.x Nova Poshta integration: live rates, in-checkout city/warehouse picker, auto-TTN, COD reconciliation, returns, webhook events.

## Tagline / pitch
The first **OpenCart 4.x-native** Nova Poshta extension that does more than slap a city dropdown on checkout. Multi-warehouse, automated TTN creation on order paid, COD payout reconciliation, return labels, plus a synthesized webhook system on top of NP's polling-only API — so your CRM, ERP, or fulfilment app can react to shipment status changes in near-real-time without writing a single cron job yourself.

## Long description

### What it does
- **In-checkout picker** — customer searches city + picks warehouse / locker / postomat without leaving your checkout page. No iframe, no Nova Poshta SDK dropdown, no jQuery wars.
- **Live rates** — real `InternetDocument.getDocumentPrice` quote based on actual cart weight and declared value. No more "₴85 flat".
- **Auto-TTN on paid** — admin picks the order status that triggers TTN creation (default: Processing). On status change, extension calls `Counterparty.save` + `InternetDocument.save` and writes the TTN number back to the order.
- **Shipments dashboard** — last 200 shipments with status badges, COD payout column, return TTN tracking, manual COD sync.
- **Synthesized webhook system** — NP itself has no webhook API. We poll `TrackingDocument.getStatusDocuments` hourly, diff status, and POST signed events to your configured URLs (HMAC-SHA256). Other extensions in the market just leave you blind.
- **Cached city + warehouse DB** — first lookup is live, subsequent are local-DB only. ~5× faster warehouse dropdown vs live API.
- **Return labels** — one-click "Create Return" on any shipment dispatches a return TTN via `AdditionalServiceGeneral.save`.
- **Encrypted API key** — XOR-obfuscated at rest with per-install secret. Not visible as plaintext to anyone with DB read access.
- **Latin → Cyrillic transliteration** — foreign customer typed `Oleksandr Petrov`? NP rejects Latin. We translit before sending and TTN goes through.

### Why this one
| Feature | This extension | $20 basic OC 4.x ext on marketplace | dev-opencart.com OC 3.x ext |
|---|---|---|---|
| OC 4.x native (Twig, namespaced, events) | ✅ | partial | ❌ (3.x only) |
| In-checkout picker | ✅ | basic dropdown | ✅ |
| Auto-TTN creation | ✅ | ❌ | ✅ |
| COD reconciliation dashboard | ✅ | ❌ | partial |
| Return labels | ✅ | ❌ | ❌ |
| Outbound webhooks | ✅ (unique) | ❌ | ❌ |
| Encrypted API key | ✅ | ❌ | ❌ |

### Requirements
- OpenCart 4.0.2 – 4.1.x (4.2+ also supported)
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- Nova Poshta merchant API key (free from `my.novaposhta.ua` → Налаштування → Безпека → API)

### Installation
1. Admin → Extensions → Installer → upload `nova_poshta_premium.ocmod.zip`.
2. Admin → Extensions → Extensions → filter `Shipping` → Install **Nova Poshta Premium**.
3. Open the extension's Edit page, click **Setup / Re-install** to create DB tables + register events/crons.
4. Paste your NP API key, click **Test Connection**, save.
5. Search Sender City → pick warehouse → click **Load** under Sender Counterparty → pick contact → save.
6. Optionally pick the order status that triggers auto-TTN creation.
7. Done. Customers see the picker on checkout, you see shipments in the new Shipments page.

### What's NOT included (yet)
- Sender Counterparty + Contact creation via API (you must have at least one set up in your NP business cabinet — most merchants already do).
- COD bank-statement matching (we show NP-side payout numbers; reconciliation against your bank CSV is v1.1).
- NP Global (cross-border) — domestic only.

## License & support
Proprietary. **₴2 990 (~$72) one-time, lifetime — no recurring fees.** Updates for the OpenCart 4.x line are included for the lifetime of the license. Sold per-domain, 2 domains per key (production + staging). Buy at https://catcode.com.ua/modules/opencart-nova-poshta-premium

Bug reports + feature requests via the vendor site contact form. SLA: 48h business-day response.
