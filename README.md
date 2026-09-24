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


### 1.2.27

- Список відділень, що приходить уже після того, як покупець почав вводити номер, тепер одразу
  з'являється у відкритому списку. Раніше меню лишалось намальованим з порожнього списку — з одним
  пунктом «Використати: «Відділення …»» — доки покупець не введе ще щось. На сторінці CatCode One Page
  Checkout так бувало щоразу, коли відділення друкували, поки сторінка ще зберігала контакти: запит
  відділень чекає своєї черги. Відповідь для міста, яке покупець уже змінив, більше не перезаписує
  список нового міста.

### 1.2.26

- Щоденна перевірка ліцензії з крону не зберігала відповідь сервера. На вітрині OpenCart у моделі
  `setting/setting` немає `editSetting()`, тож виклик кидав виняток і обривав увесь прогін крону
  OpenCart 4: завдання, що стояли в черзі після перевірки (щотижнева синхронізація міст, звірка
  післяплати, а також завдання ядра й інших модулів), не виконувались узагалі. Дата перевірки застигала,
  і через 14 днів Pro-функції (автооновлення статусів ТТН, вебхуки, звірка післяплати) вимикались, хоча
  ліцензія дійсна. Тепер перевірка пише налаштування напряму в таблицю, по рядку на ключ.
- Куплена ліцензія, раз підтверджена сервером, лишає Pro назавжди: жоден пізніший вердикт чи недоступність
  сервера її не вимикає. Пробний ключ діє рівно 7 днів від старту, у тому числі без зв'язку з сервером.

### 1.2.25

- CatCode One Page Checkout: віджет іноді не з'являвся — Нова Пошта обрана, а вибору міста й відділення
  немає. Віджет чекав коду методу в `#input-shipping-code`, а One Page пише його туди лише після того, як
  сервер прийняв збереження. Перші запити віджета (відновлення вибору) йшли паралельно з першим розрахунком
  доставки One Page; OpenCart записує сесію цілком наприкінці кожного запиту, тож список розрахованих методів
  інколи губився і збереження методу відповідало «Потрібний спосіб доставки!» — код не приходив ніколи.
  Тепер віджет дивиться на перемикач, який обрав покупець (`cc_shipping_method`), реагує на кожне
  перемальовування списку методів, а свої запити на One Page запускає після того, як чекаут вишикував їх у
  власну чергу. Та сама правка — в Укрпошті 1.2.7 і ROZETKA Delivery 1.1.6 (оновлювати разом).

### 1.2.24

- Віджет ховає й заповнює штатні поля адреси лише тоді, коли обрано саму Нову Пошту. Для кур'єра,
  самовивозу чи іншого методу поля адреси знову видимі й з тим, що вводив покупець (заглушки
  «Україна» / «Відділення перевізника» прибираються), обов'язкові поля перевіряє ядро, а кнопка
  підтвердження чекає, доки справжню адресу збережено. Раніше в кур'єрське замовлення йшла адреса-заглушка.
  Та сама логіка — в Укрпошті 1.2.6 і ROZETKA Delivery 1.1.5 (оновлювати разом).
- Запасне зіставлення області (якщо сервер не визначив) більше не бере першу зону країни, коли назви
  зон магазину кирилицею.

### 1.2.23

- Прихована форма адреси більше не отримує індекс-заглушку «00000»: поле лишається порожнім, заглушка
  пишеться лише для країни, де індекс обов'язковий (налаштування країни в магазині). Інші способи
  доставки, що рахують ціну за індексом (зони кур'єрської доставки), читали «00000» як справжній
  індекс покупця і ховали свій метод.

### 1.2.22

- Область у замовленні визначає сервер. OpenCart 4 створює замовлення при першому показі блоку
  підтвердження, а потім переписує його із сесії (editOrder) при кожному оновленні — і повертав
  у замовлення засів прихованої форми адреси: «Автономна Республіка Крим» для київського
  відділення (замовлення, де адресу збережено до вибору міста). Тепер після addOrder і після
  кожного editOrder модуль сам записує місто, відділення й область: область береться зі свого
  довідника міст НП за Ref міста, зіставляється із зонами магазину транслітерацією (Київ і
  Севастополь — окремими зонами-містами), а без однозначного збігу `shipping_zone_id` = 0 і
  назва області НП текстом — випадкова зона не підставляється. Подія editOrder реєструється
  кнопкою «Setup» або сама при першому відкритті чекауту після оновлення файлів.
- Тариф перераховується на сервері після вибору відділення: `setSelection` переоцінює вже
  обраний метод Нової Пошти й оновлює його в сесії, тож сума замовлення правильна навіть там,
  де тема чи однокроковий чекаут не перезберігає метод доставки.
- Вибір міста/відділення більше не губиться на однокроковому чекауті. OpenCart записує сесію
  цілком наприкінці кожного запиту, і паралельний register.save / shipping_method.save, що
  стартував до `setSelection`, повертав стару сесію: без обраного міста (у замовлення йшов
  запасний тариф, без чернетки ТТН) або з ПОПЕРЕДНІМ містом (обрали Київ — у замовленні Львів).
  Тепер `setSelection` дублює вибір у cookie, прив'язану до id сесії, і модуль відновлює з неї
  останній вибір перед котируванням і записом замовлення.
- Чернетка відправлення створюється й тоді, коли місто обрано вже після створення замовлення, а
  при зміні перевізника порожня чернетка (без ТТН) прибирається.

### 1.2.21

- Заглушка області в прихованому полі адреси більше не «перша зона країни». Стандартний список зон
  України починається з «Avtonomna Respublika Krym» / «Cherkas'ka Oblast'», і цей засів доїжджав
  до замовлення щоразу, коли область міста НП не знайшла відповідника в зонах магазину
  (наприклад, список зон російською або змінений вручну). Тепер заглушка — Київ.

### 1.2.20

- Вартість доставки перераховується після вибору відділення. Віджет відкривається вже після
  вибору перевізника, тож збережена ядром котировка рахувалась без міста отримувача — у
  замовлення йшов запасний фіксований тариф з налаштувань, а не жива ставка Нової Пошти.
  `setSelection` тепер скидає кеш котировок, а віджет перезапитує і перезберігає метод
  (повертаючи обраний спосіб оплати, який ядро при цьому гасить), тож змінюється сума
  замовлення, а не лише напис у списку.

### 1.2.19

- Працює на CatCode One Page Checkout (маршрут `extension/cc_onepage/checkout`) так само, як на
  штатному чекауті: віджет міста/відділення монтується, стартова адреса засівається, вибране
  відділення потрапляє в адресу замовлення і в чернетку ТТН. Раніше перевірка маршруту пускала
  лише `checkout/checkout`, і на однокроковому чекауті блоку НП не було.
- На One Page Checkout віджет більше не відновлює метод доставки/оплати після `register.save`
  паралельно з самим чекаутом (той робить це у своїй черзі): через перегони список оплат
  порожнів із «Потрібний спосіб доставки!».
- OpenCart 4.1: вибір відділення падав з `Unknown column 'name'` (назви зон у 4.1 живуть у
  `zone_description`), і область у сесійній адресі не підставлялась. Зони тепер читаються
  через модель ядра `localisation/zone` — однаково на 4.0 і 4.1.

### 1.2.18

- API-ключ і ліцензійний ключ шифруються AES-256-CBC з HMAC замість XOR-циклу (антивіруси
  хостингів видаляли `crypto.php`); збережені раніше ключі читаються без повторного введення.

### 1.2.17

- Зворотна ТТН більше не падає на валідації Нової Пошти: у запиті бракувало підтипу
  причини повернення (`SubtypeReason`), а відділення підставлялось у `ReturnAddressRef`,
  яке чекає адресу контрагента, а не відділення. Тепер шлється `SubtypeReason` +
  `RecipientWarehouse`, а перед створенням модуль питає `CheckPossibilityCreateReturn`
  і показує відповідь перевізника замість сирої помилки.


### 1.2.16
- **Live rate calculation is now opt-in and off by default.** Until now the module
  always replaced the merchant's cost with the carrier tariff from
  `InternetDocument.getDocumentPrice` as soon as an API key and a sender city were
  configured — stores that wanted "paid to the carrier on pickup" had no way to turn
  that off, and the cost field (previously labelled *Fallback shipping cost*) looked
  like it was being ignored. Pricing now has a **Live rate calculation via API**
  switch, defaulting to off for both fresh installs and upgrades; the cost field is
  what checkout shows, and the API tariff is used only when the switch is on
  (still falling back to that field if Nova Poshta does not answer).
- **A zero shipping cost no longer reads as "free delivery".** When the cost comes
  out at 0 the method is now labelled *(оплата при отриманні)* and priced
  *за тарифами перевізника* instead of showing `0 ₴` — the suffix goes on the
  method name as well, because the order totals row reuses it verbatim.
- **Fixed: an empty pink chip hung under the picker until a warehouse was chosen.**
  The summary line is toggled with the `hidden` attribute, but `.np-summary`
  declared `display:inline-flex`, which outranks the user-agent `[hidden]` rule —
  so the styled-but-empty element stayed on screen.

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
