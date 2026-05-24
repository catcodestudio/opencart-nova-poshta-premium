# Нова Пошта Premium для OpenCart 4.x

## Заголовок (60 симв.)
Нова Пошта Premium — OC 4.x native shipping + автоматизація ТТН

## Короткий опис (160 симв.)
Нативна інтеграція OC 4.x з Новою Поштою: live тарифи, picker у checkout, авто-ТТН, реконсиляція післяплати, повернення, webhook-події.

## Tagline / пітч
Перший **OpenCart 4.x-native** модуль Нової Пошти, який робить більше за просто dropdown міст у checkout. Multi-warehouse, авто-створення ТТН при оплаті, дашборд звірки післяплати, зворотні накладні, плюс синтезована webhook-система поверх polling-only API НП — щоб ваш CRM / ERP / фулфілмент реагували на зміни статусів майже в реалтаймі без жодного власного cron-а.

## Розгорнутий опис

### Що робить
- **In-checkout picker** — клієнт шукає місто і обирає відділення / поштомат прямо у вашому checkout. Без iframe, без NP SDK dropdown, без війн з jQuery.
- **Live тарифи** — реальний `InternetDocument.getDocumentPrice` за фактичною вагою кошика і оголошеною вартістю. Кінець "₴85 flat".
- **Авто-ТТН при оплаті** — адмін обирає статус замовлення, що тригерить створення ТТН (default: Processing). На зміні статусу модуль кличе `Counterparty.save` + `InternetDocument.save` і пише номер ТТН назад у замовлення.
- **Shipments dashboard** — останні 200 відправлень з status badges, колонкою виплат COD, треком зворотних ТТН, ручним sync COD.
- **Синтезована webhook-система** — у НП API webhook-ів немає взагалі. Ми polling-имо `TrackingDocument.getStatusDocuments` щогодини, діффимо статус, і POST-имо підписані події на ваші URL (HMAC-SHA256). Інших таких на ринку немає.
- **Кеш міст + складів** — перший пошук live, далі — локальний DB. ~5× швидше за live API при виборі складу.
- **Зворотні накладні** — кнопка "Create Return" на будь-якому відправленні викликає `AdditionalServiceGeneral.save` і випускає зворотну ТТН.
- **Зашифрований API ключ** — XOR-обфускація at-rest з per-install secret. Не бачать у плейн-тексті ті, хто має read-доступ до БД.
- **Translit Latin → Cyrillic** — іноземний клієнт ввів `Oleksandr Petrov`? НП Latin не приймає. Ми транслітеруємо перед відправкою і ТТН проходить.

### Чому саме цей
| Фіча | Цей модуль | $20 базовий OC 4.x на marketplace | dev-opencart.com OC 3.x |
|---|---|---|---|
| OC 4.x native (Twig, namespaces, events) | ✅ | частково | ❌ (тільки 3.x) |
| In-checkout picker | ✅ | базовий dropdown | ✅ |
| Авто-ТТН | ✅ | ❌ | ✅ |
| Дашборд COD | ✅ | ❌ | частково |
| Зворотні ТТН | ✅ | ❌ | ❌ |
| Outbound webhooks | ✅ (унікально) | ❌ | ❌ |
| Шифрування API key | ✅ | ❌ | ❌ |

### Вимоги
- OpenCart 4.0.2 – 4.1.x (4.2+ теж працює)
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- API ключ Нової Пошти (безкоштовний у `my.novaposhta.ua` → Налаштування → Безпека → API)

### Встановлення
1. Адмінка → Extensions → Installer → завантажити `nova-poshta-premium.ocmod.zip`.
2. Адмінка → Extensions → Extensions → фільтр `Shipping` → Install **Nova Poshta Premium**.
3. Відкрити Edit на розширенні, тиснути **Setup / Re-install** для створення таблиць + реєстрації events/crons.
4. Вставити NP API ключ, **Test Connection**, зберегти.
5. Шукати Sender City → обрати склад → клік **Load** під Sender Counterparty → обрати контакт → зберегти.
6. (Опціонально) обрати статус замовлення, що тригерить авто-ТТН.
7. Готово. Клієнти бачать picker на checkout, ви — відправлення на новій сторінці Shipments.

### Що НЕ входить (поки що)
- Створення Sender Counterparty + Contact через API (треба мати хоча б одного у бізнес-кабінеті НП — у більшості мерчантів вони вже є).
- Звірка post-payment проти bank statement CSV — показуємо номери виплат від НП, повна реконсиляція з банком — v1.1.
- НП Глобал (cross-border) — поки тільки domestic.

## Ліцензія + підтримка
Власницький. **₴2 990 одноразово + ₴990/рік підтримки** (поновлення = майбутні оновлення + Pro-фічі після 14-денного offline grace period). Ключ на 2 домени (dev + prod). Купити: https://catcode.com.ua/opencart/nova-poshta-premium

Баги + feature requests через контактну форму на vendor сайті. SLA: 48h business-day response.
