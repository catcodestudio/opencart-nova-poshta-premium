# Marketplace Submission Guide — Nova Poshta Premium

## Assets ready in this folder

```
marketplace/
├── MARKETPLACE.md              ← this file
├── sales-copy-en.md            ← English title, descriptions, comparison
├── sales-copy-uk.md            ← Ukrainian title, descriptions, comparison
└── screenshots/
    ├── np-premium-marketplace-01-admin-settings.png   ← Admin Settings (all 5 sections populated)
    ├── np-premium-marketplace-02-shipments.png        ← Shipments dashboard (4 demo rows, COD + Return columns)
    └── np-premium-marketplace-03-storefront-picker.png ← In-checkout picker with Київ + Відділення №1 pre-selected
```

The shipping zip lives at `../build/nova_poshta_premium.ocmod.zip` (rebuild via `..\build.ps1`).

## Distribution channels

### 1. opencart.com Marketplace (primary)

URL: `https://www.opencart.com/index.php?route=account/extension/extension`

**Step-by-step:**
1. Sign in to your `opencart.com` account (create one with the business email if needed).
2. Go to **My Extensions → Add Extension**.
3. Pick category: `Shipping` (NOT Modules — Marketplace filters by exact category).
4. **Title** = "Nova Poshta Premium" (60-char limit per `sales-copy-en.md`).
5. **Description** = paste the Long description from `sales-copy-en.md`. Marketplace supports basic HTML formatting; bullets render OK.
6. **Versions tested** = `4.0.2.0, 4.0.2.1, 4.0.2.2, 4.0.2.3, 4.1.0.0, 4.1.0.1, 4.1.0.2, 4.1.0.3` (compatibility list — pick from their dropdown).
7. **Image** = upload `screenshots/np-premium-marketplace-01-admin-settings.png` as the main thumbnail.
8. **Screenshots** = upload all three from `screenshots/`. Order: settings → shipments → storefront.
9. **Upload extension** = `build/nova_poshta_premium.ocmod.zip`.
10. **Price** = $0 (Free listing). The plugin works without a license; premium features unlock with a key sold on catcode.com.ua. opencart.com is a brand / funnel channel, not a revenue channel for this plugin.
11. **Demo URL** (optional) = if you set up a public OC store with the module installed, link it here. Otherwise leave empty — most premium extensions skip this.
12. **Submit for review**. opencart.com manual review typically 3–10 business days.

**Common rejection reasons (from forum reports):**
- Namespace collisions with core (we use `NovaPoshtaPremium` — unique).
- Missing `uninstall()` cleanup (we have it).
- Hard-coded URLs (we use OC's URL helpers).
- Missing `uk-ua` + `en-gb` language files (we have both).
- Unescaped SQL (we use `$this->db->escape()` consistently).
- `var` in JS (we use `const`/`let`).

If review fails, fix the issue, bump `install.json` version, and re-submit. Use the same listing — don't create a duplicate.

### 2. Vendor site (secondary, higher margin)

Sell directly from `catcode.com.ua/modules/opencart-nova-poshta-premium` (not yet built). Skip 20% marketplace cut.

Suggested page structure:
- Hero — tagline from `sales-copy-en.md`
- 3 screenshots from `screenshots/`
- Feature comparison table
- Pricing card (₴2 990 lifetime badge)
- Buy button → Lemon Squeezy / Paddle / Stripe Checkout
- License key delivery via email on successful payment
- Download link (signed S3/CDN URL, expires after 24h)
- Documentation link (this README)

### 3. CodeCanyon — AVOIDED (per [[project-build-decisions]] memory)

Race-to-bottom pricing, ~30% cut, premium positioning impossible.

## Pricing model details

- **₴2 990 one-time, lifetime** — perpetual license, no recurring fees. Premium features (COD, Returns, Status polling, Webhooks, Multi-warehouse) unlock immediately on activation and stay unlocked forever for the licensed domains. Updates for current OpenCart majors (4.x) are free for the lifetime of the license.
- **Future major versions (v2.0+)** — when we ship a major release with substantially new value, existing customers get a discounted upgrade offer. No surprise paywalls on patch / minor releases.
- **2 domains per key** — production + staging (or 2 brands). Customer can self-manage via license cabinet.
- **30-day refund** — standard, opencart.com requires this anyway.

## Submission checklist

- [ ] `build/nova_poshta_premium.ocmod.zip` rebuilt with latest commit
- [ ] All 3 screenshots present and ≤2MB each (marketplace limit)
- [ ] Sales copy proofed for typos
- [ ] Demo URL working OR explicitly left blank
- [ ] Vendor site landing page live (or "coming soon" page)
- [ ] License delivery flow tested end-to-end
- [ ] `LICENSE` file present in zip (proprietary, vendor name in header)
- [ ] `install.json` version matches what's in the readme
- [ ] Support email monitored (vendor site contact form forwards to inbox)

## Post-launch first week

- Monitor opencart.com forum thread (review feedback often arrives via PM not email)
- Set up Freelancehunt / kabanchik alert for "OpenCart Nova Poshta" — customers searching for solutions often post there first; quick reply with "we have a 4.x module" = ~30% conversion based on competitor data
- Check `dev-opencart.com` and `opencartbot.com` quarterly — if either ports their NP to 4.x, time to add a differentiating feature
