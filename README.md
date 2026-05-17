# Nova Poshta Premium for OpenCart 4.x

Premium-tier Nova Poshta shipping integration for OpenCart 4.x merchants.

**Status:** v0 — scaffolding. Not yet functional.

## What this is

OpenCart 4.x shipping extension targeting Ukrainian merchants who use Nova Poshta. Differentiates from the existing $20 basic modules by offering:

- Multi-warehouse sender support
- Automatic TTN (waybill) creation on order paid
- COD reconciliation dashboard (читання payouts)
- Outbound webhooks on shipment status change (NP itself has no webhook API — we synthesize via polling)
- Cached city/warehouse DB with weekly delta sync
- Branch / locker / postomat picker with type filter

## Compatibility

- OpenCart 4.0.2 – 4.1.x (4.2+ supported, Tasks API used opportunistically when present)
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+

## License

Proprietary. Sold per-domain via vendor site. Renewal €39/yr unlocks future updates and premium features beyond 14-day offline grace.

## Project layout

```
upload/                  # what gets zipped into .ocmod.zip and installed
  install.json
  admin/
  catalog/
  system/
ocmod/                   # optional XML patches (avoided where events suffice)
build/                   # zip output (gitignored)
docs/                    # internal dev notes (not shipped)
```

## Development

- Dev server: https://oc.catcode.com.ua/ (OC 4.1.0.3)
- Deploy via SSH: `scp -r upload/* wpcat@wpcat.ftp.tools:/home/wpcat/catcode.com.ua/oc/extension/nova_poshta_premium/`
