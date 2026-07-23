(() => {
  const cfg = window.__novaPoshtaPremium;
  if (!cfg || window.__npPickerMounted) return;

  // Storefront checkout only — never the cart or other pages.
  const route = new URLSearchParams(location.search).get('route') || '';
  if (route !== 'checkout/checkout') return;

  const accent = /^#[0-9a-fA-F]{6}$/.test(cfg.accentColor || '') ? cfg.accentColor : '#da291c';
  // Corner radius is merchant-configurable so the widget matches the host
  // theme's roundness (0 = sharp, up to 24px = very rounded). Falls back to 14.
  const radiusRaw = parseInt(cfg.radius, 10);
  const radius = Number.isFinite(radiusRaw) ? Math.max(0, Math.min(28, radiusRaw)) : 14;
  // Theme: 'auto' inherits the site's Bootstrap palette (default), 'light' and
  // 'dark' pin an explicit surface so the block reads well on any template.
  const theme = ['auto', 'light', 'dark'].includes(cfg.theme) ? cfg.theme : 'auto';
  const surfaceVars = theme === 'light'
    ? '--np-surface:#fff;--np-text:#18181b;--np-muted:#71717a;--np-line:#e4e4e7;--np-soft:#f4f4f5;'
    : theme === 'dark'
      ? '--np-surface:#1e1e22;--np-text:#f4f4f5;--np-muted:#a1a1aa;--np-line:#33333a;--np-soft:#26262b;'
      : '--np-surface:var(--bs-body-bg,#fff);--np-text:var(--bs-body-color,#18181b);--np-muted:var(--bs-secondary-color,#71717a);--np-line:var(--bs-border-color,#e4e4e7);--np-soft:var(--bs-secondary-bg,#f4f4f5);';

  const STYLE = `
  .np-box{--np-accent:${accent};--np-radius:${radius}px;${surfaceVars}border:1px solid var(--np-line);border-radius:var(--np-radius);margin:0 0 20px;background:var(--np-surface);box-shadow:0 1px 2px rgba(0,0,0,.04);}
  .np-box__head{display:flex;align-items:center;gap:10px;font-weight:600;font-size:15px;padding:14px 18px;color:var(--np-text);background:color-mix(in srgb,var(--np-accent) 7%,transparent);border-bottom:1px solid var(--np-line);border-radius:var(--np-radius) var(--np-radius) 0 0;}
  .np-box__logo{width:26px;height:26px;border-radius:calc(var(--np-radius) * .45);background:var(--np-accent);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;letter-spacing:-.5px;flex:none;}
  .np-box__body{padding:16px 18px 16px;}
  .np-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
  @media(max-width:640px){.np-grid{grid-template-columns:1fr;}}
  .np-field{position:relative;}
  .np-field>label{display:block;font-size:12px;font-weight:500;color:var(--np-muted);margin-bottom:6px;}
  .np-input{width:100%;height:44px;padding:0 38px 0 13px;border:1px solid var(--np-line);border-radius:calc(var(--np-radius) * .7);font-size:14px;line-height:42px;background:var(--np-surface);color:var(--np-text);transition:border-color .15s,box-shadow .15s;}
  .np-input::placeholder{color:var(--np-muted);opacity:.8;}
  .np-input:focus{outline:none;border-color:var(--np-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--np-accent) 20%,transparent);}
  .np-input:disabled{background:var(--np-soft);color:var(--np-muted);cursor:not-allowed;}
  .np-field__icon{position:absolute;right:13px;bottom:0;height:44px;display:flex;align-items:center;color:var(--np-muted);pointer-events:none;}
  .np-menu{position:absolute;left:0;right:0;top:calc(100% + 5px);z-index:60;background:var(--np-surface);border:1px solid var(--np-line);border-radius:calc(var(--np-radius) * .8);box-shadow:0 10px 34px rgba(0,0,0,.16);max-height:264px;overflow-y:auto;display:none;}
  .np-menu.is-open{display:block;}
  .np-opt{padding:10px 13px;font-size:14px;cursor:pointer;border-bottom:1px solid var(--np-line);color:var(--np-text);}
  .np-opt:last-child{border-bottom:0;}
  .np-opt small{display:block;color:var(--np-muted);font-size:12px;margin-top:1px;}
  .np-opt:hover,.np-opt.is-active{background:color-mix(in srgb,var(--np-accent) 10%,transparent);}
  .np-opt--muted{color:var(--np-muted);cursor:default;text-align:center;}
  .np-opt--head{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--np-muted);cursor:default;background:var(--np-soft);padding:7px 13px;}
  .np-summary{display:inline-flex;align-items:center;gap:8px;margin-top:14px;padding:9px 13px;font-size:13px;font-weight:600;color:var(--np-accent);background:color-mix(in srgb,var(--np-accent) 9%,transparent);border-radius:calc(var(--np-radius) * .65);}
  .np-spin{display:inline-block;width:15px;height:15px;border:2px solid color-mix(in srgb,var(--np-accent) 28%,transparent);border-top-color:var(--np-accent);border-radius:50%;animation:np-spin .6s linear infinite;vertical-align:middle;}
  @keyframes np-spin{to{transform:rotate(360deg)}}
  .np-native-hidden{display:none!important;}
  .np-gated{display:none!important;}`;

  const SVG = (p, w) => `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${w || 1.9}" stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
  const ICON_PIN = SVG('<path d="M20 10c0 4.4-8 12-8 12s-8-7.6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>');
  const ICON_BOX = SVG('<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>');
  const ICON_OK  = SVG('<polyline points="20 6 9 17 4 12"/>', 2.4);
  // Header glyph — neutral delivery mark (NOT a reproduction of the Nova Poshta
  // trademark logo). Merchants may swap in the official logo if they hold rights.
  const ICON_TRUCK = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h2"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="7.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></svg>';

  const t = {
    title: 'Доставка Новою Поштою',
    city: 'Місто',
    cityPh: 'Почніть вводити назву міста…',
    wh: 'Відділення або поштомат',
    whPh: 'Спочатку оберіть місто',
    whReady: 'Оберіть або введіть номер відділення…',
    searching: 'Пошук…',
    loading: 'Завантажуємо відділення…',
    noCity: 'Нічого не знайдено',
    noWh: 'Збігів немає',
    popular: 'Популярні міста',
  };

  // Popular Ukrainian cities — instant pick without typing. Real Nova Poshta
  // city refs (stable UUIDs) so a click loads warehouses immediately.
  const POPULAR = [
    {ref:'8d5a980d-391c-11dd-90d9-001a92567626',name:'Київ',area:'Київська'},
    {ref:'db5c88e0-391c-11dd-90d9-001a92567626',name:'Харків',area:'Харківська'},
    {ref:'db5c88d0-391c-11dd-90d9-001a92567626',name:'Одеса',area:'Одеська'},
    {ref:'db5c88f0-391c-11dd-90d9-001a92567626',name:'Дніпро',area:'Дніпропетровська'},
    {ref:'db5c88f5-391c-11dd-90d9-001a92567626',name:'Львів',area:'Львівська'},
    {ref:'db5c88c6-391c-11dd-90d9-001a92567626',name:'Запоріжжя',area:'Запорізька'},
    {ref:'db5c890d-391c-11dd-90d9-001a92567626',name:'Кривий Ріг',area:'Дніпропетровська'},
    {ref:'db5c888c-391c-11dd-90d9-001a92567626',name:'Миколаїв',area:'Миколаївська'},
    {ref:'db5c88de-391c-11dd-90d9-001a92567626',name:'Вінниця',area:'Вінницька'},
    {ref:'db5c8892-391c-11dd-90d9-001a92567626',name:'Полтава',area:'Полтавська'},
    {ref:'db5c897c-391c-11dd-90d9-001a92567626',name:'Чернігів',area:'Чернігівська'},
    {ref:'db5c8902-391c-11dd-90d9-001a92567626',name:'Черкаси',area:'Черкаська'},
    {ref:'db5c8904-391c-11dd-90d9-001a92567626',name:'Івано-Франківськ',area:'Івано-Франківська'},
    {ref:'db5c8900-391c-11dd-90d9-001a92567626',name:'Тернопіль',area:'Тернопільська'},
  ];

  const el = (html) => { const d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstElementChild; };
  const debounce = (fn, ms) => { let h; return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); }; };

  const api = (url, body) => {
    const opts = { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin' };
    if (body) opts.body = new URLSearchParams(body);
    return fetch(url, opts).then((r) => r.json());
  };

  const wrap = el(`
    <div class="np-box">
      <div class="np-box__head"><span class="np-box__logo">${ICON_TRUCK}</span><span>${t.title}</span></div>
      <div class="np-box__body">
        <div class="np-grid">
          <div class="np-field" data-np="city">
            <label for="np-city-q">${t.city}</label>
            <input class="np-input" id="np-city-q" type="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-form-type="other" readonly onfocus="this.removeAttribute('readonly')" placeholder="${t.cityPh}">
            <span class="np-field__icon">${ICON_PIN}</span>
            <div class="np-menu" id="np-city-results" role="listbox"></div>
          </div>
          <div class="np-field" data-np="wh">
            <label for="np-wh-q">${t.wh}</label>
            <input class="np-input" id="np-wh-q" type="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-form-type="other" readonly onfocus="this.removeAttribute('readonly')" placeholder="${t.whPh}" disabled>
            <span class="np-field__icon">${ICON_BOX}</span>
            <div class="np-menu" id="np-wh-results" role="listbox"></div>
            <input type="hidden" id="np-warehouse">
          </div>
        </div>
        <div class="np-summary" id="np-summary" hidden></div>
      </div>
    </div>`);

  const style = document.createElement('style');
  style.textContent = STYLE;

  // --- mount inside the checkout flow, never as a floating banner ---
  const mount = () => {
    const anchors = ['#shipping-address', '#checkout-payment-method', '#checkout-confirm', '#checkout-checkout'];
    for (const sel of anchors) {
      const node = document.querySelector(sel);
      if (!node) continue;
      const visible = node.offsetParent !== null || sel === '#checkout-checkout';
      if (!visible) continue;
      if (sel === '#shipping-address' || sel === '#checkout-checkout') {
        node.insertBefore(wrap, node.firstChild);
      } else {
        node.parentNode.insertBefore(wrap, node);
      }
      document.head.appendChild(style);
      return true;
    }
    return false;
  };

  // --- state ---
  let cityRef = '';
  let cityName = '';
  let cityArea = '';
  let warehouses = [];

  // --- native OpenCart shipping-address bridge ------------------------------
  // The NP block fully replaces the manual address form for the customer, but
  // OpenCart still requires its native shipping fields to validate/persist the
  // order. We visually hide them and keep them populated from the NP choice.
  const NATIVE = {
    company:  '#input-shipping-company',
    address1: '#input-shipping-address-1',
    address2: '#input-shipping-address-2',
    city:     '#input-shipping-city',
    postcode: '#input-shipping-postcode',
    country:  '#input-shipping-country',
    zone:     '#input-shipping-zone',
  };
  const q1 = (s) => document.querySelector(s);
  const setVal = (el, v) => {
    if (!el) return;
    el.value = v;
    el.dispatchEvent(new Event('input',  { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  };

  const hideNativeAddress = () => {
    const host = document.querySelector('#shipping-address');
    if (!host) return;
    // Hide every child of the shipping-address fieldset except our NP block —
    // this removes the legend, all native field rows AND any stray required
    // asterisks, leaving a single clean Nova Poshta widget. The native inputs
    // stay in the DOM (display:none) so the order still submits/validates.
    [...host.children].forEach((child) => {
      if (child === wrap || child.contains(wrap)) return;
      // не ховати віджет Укрпошти й панель перемикача перевізників — ними керує координатор
      if (child.classList.contains('up-box') || child.querySelector('.up-box')) return;
      if (child.classList.contains('cc-switch')) return;
      child.classList.add('np-native-hidden');
    });
  };

  // Keep the (hidden) native fields valid so checkout never blocks on them.
  const fillNativeAddress = () => {
    const country = q1(NATIVE.country);
    if (country && country.value !== '220') {
      const opt = [...country.options].find((o) => /Україна|Ukraine/i.test(o.text));
      if (opt) { country.value = opt.value; country.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    const zone = q1(NATIVE.zone);
    if (zone) {
      let opt = cityArea && [...zone.options].find((o) => o.value && o.text.includes(cityArea));
      if (!opt) opt = [...zone.options].find((o) => o.value && /Київ/.test(o.text));
      if (!opt) opt = [...zone.options].find((o) => o.value);
      if (opt && zone.value !== opt.value) { zone.value = opt.value; zone.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    setVal(q1(NATIVE.city),     cityName || 'Україна');
    setVal(q1(NATIVE.address1), whHidden().dataset.name || whHidden().value || 'Нова Пошта');
    setVal(q1(NATIVE.postcode), '00000');
  };

  const cityInput = () => wrap.querySelector('#np-city-q');
  const cityMenu = () => wrap.querySelector('#np-city-results');
  const whInput = () => wrap.querySelector('#np-wh-q');
  const whMenu = () => wrap.querySelector('#np-wh-results');
  const whHidden = () => wrap.querySelector('#np-warehouse');
  const summary = () => wrap.querySelector('#np-summary');

  const openMenu = (m) => m.classList.add('is-open');
  const closeMenu = (m) => m.classList.remove('is-open');
  const muted = (text) => `<div class="np-opt np-opt--muted">${text}</div>`;

  const renderSummary = () => {
    const s = summary();
    const whName = whHidden().dataset.name || '';
    if (cityName && whName) {
      s.innerHTML = `${ICON_OK}<span>${cityName} — ${whName}</span>`;
      s.hidden = false;
    } else {
      s.hidden = true;
    }
  };

  // --- demo dataset — lets the picker be clicked/tested without a live key ---
  const DEMO_CITIES = [
    { ref: 'demo-kyiv',    name: 'Київ',   area: 'Київська' },
    { ref: 'demo-kharkiv', name: 'Харків', area: 'Харківська' },
    { ref: 'demo-lviv',    name: 'Львів',  area: 'Львівська' },
    { ref: 'demo-odesa',   name: 'Одеса',  area: 'Одеська' },
    { ref: 'demo-dnipro',  name: 'Дніпро', area: 'Дніпропетровська' },
  ];
  const DEMO_WH = [
    { ref: 'demo-wh-1', description: 'Відділення №1: вул. Демонстраційна, 1' },
    { ref: 'demo-wh-2', description: 'Відділення №2: просп. Прикладу, 25' },
    { ref: 'demo-wh-3', description: 'Поштомат №1: ТЦ «Приклад»' },
  ];
  const demoCities = (q) => {
    const qq = (q || '').trim().toLowerCase();
    return DEMO_CITIES.filter((c) => !qq || c.name.toLowerCase().includes(qq));
  };
  const cityOptsHtml = (list) => list.map((c) =>
    `<div class="np-opt" role="option" data-ref="${c.ref}" data-name="${c.name.replace(/"/g, '&quot;')}" data-area="${(c.area || '').replace(/"/g, '&quot;')}">${c.name}${c.area ? `<small>${c.area} область</small>` : ''}</div>`
  ).join('');

  // --- city combobox ---
  const searchCities = debounce((q) => {
    const menu = cityMenu();
    api(cfg.searchCities, { q }).then((d) => {
      let list = (d && d.cities) || [];
      if (!list.length) list = demoCities(q);
      if (!list.length) { menu.innerHTML = muted(t.noCity); openMenu(menu); return; }
      menu.innerHTML = cityOptsHtml(list);
      openMenu(menu);
    }).catch(() => {
      const list = demoCities(q);
      menu.innerHTML = list.length ? cityOptsHtml(list) : muted(t.noCity);
      openMenu(menu);
    });
  }, 280);

  // Popular cities shown on focus (empty input) for one-click selection.
  const renderPopular = () => {
    const menu = cityMenu();
    menu.innerHTML = `<div class="np-opt np-opt--head">${t.popular}</div>` + POPULAR.map((c) =>
      `<div class="np-opt" role="option" data-ref="${c.ref}" data-name="${c.name.replace(/"/g, '&quot;')}" data-area="${c.area.replace(/"/g, '&quot;')}">${c.name}<small>${c.area} область</small></div>`
    ).join('');
    openMenu(menu);
  };

  const pickCity = (ref, name, area) => {
    cityRef = ref; cityName = name; cityArea = area || '';
    cityInput().value = name;
    closeMenu(cityMenu());
    const w = whInput();
    w.disabled = false;
    w.value = '';
    w.placeholder = t.loading;
    whHidden().value = '';
    whHidden().dataset.name = '';
    warehouses = [];
    renderSummary();
    fillNativeAddress();
    api(cfg.getWarehouses, { city_ref: ref }).then((d) => {
      warehouses = (d && d.warehouses) || [];
      if (!warehouses.length) warehouses = DEMO_WH.slice();
      w.placeholder = t.whReady;
    }).catch(() => { warehouses = DEMO_WH.slice(); w.placeholder = t.whReady; });
  };

  // --- warehouse combobox (client-side filter over loaded list) ---
  const renderWh = (filter) => {
    const menu = whMenu();
    const f = (filter || '').trim().toLowerCase();
    const list = f ? warehouses.filter((w) => w.description.toLowerCase().includes(f)) : warehouses;
    if (!warehouses.length) { menu.innerHTML = muted(t.loading); openMenu(menu); return; }
    if (!list.length) { menu.innerHTML = muted(t.noWh); openMenu(menu); return; }
    menu.innerHTML = list.slice(0, 60).map((w) =>
      `<div class="np-opt" role="option" data-ref="${w.ref}" data-name="${w.description.replace(/"/g, '&quot;')}">${w.description}</div>`
    ).join('');
    openMenu(menu);
  };

  const pickWh = (ref, name) => {
    whInput().value = name;
    whHidden().value = ref;
    whHidden().dataset.name = name;
    closeMenu(whMenu());
    renderSummary();
    fillNativeAddress();
    api(cfg.setSelection, { city_ref: cityRef, city_name: cityName, warehouse_ref: ref, warehouse_name: name });
  };

  // keyboard navigation within a menu
  const keyNav = (menu, e) => {
    const opts = [...menu.querySelectorAll('.np-opt[data-ref]')];
    if (!opts.length) return;
    let i = opts.findIndex((o) => o.classList.contains('is-active'));
    if (e.key === 'ArrowDown') { e.preventDefault(); i = (i + 1) % opts.length; }
    else if (e.key === 'ArrowUp') { e.preventDefault(); i = (i - 1 + opts.length) % opts.length; }
    else if (e.key === 'Enter' && i >= 0) { e.preventDefault(); opts[i].click(); return; }
    else if (e.key === 'Escape') { closeMenu(menu); return; }
    else return;
    opts.forEach((o) => o.classList.remove('is-active'));
    opts[i].classList.add('is-active');
    opts[i].scrollIntoView({ block: 'nearest' });
  };

  const bind = () => {
    const ci = cityInput(), cm = cityMenu(), wi = whInput(), wm = whMenu();

    ci.addEventListener('focus', () => {
      if (!cityRef && ci.value.trim().length < 2) renderPopular();
    });
    ci.addEventListener('input', () => {
      const v = ci.value.trim();
      cityRef = ''; renderSummary();
      if (v.length < 2) { renderPopular(); return; }
      cm.innerHTML = muted(`<span class="np-spin"></span> ${t.searching}`); openMenu(cm);
      searchCities(v);
    });
    cm.addEventListener('click', (e) => {
      const o = e.target.closest('.np-opt[data-ref]'); if (!o) return;
      pickCity(o.dataset.ref, o.dataset.name, o.dataset.area);
    });
    ci.addEventListener('keydown', (e) => keyNav(cm, e));

    wi.addEventListener('focus', () => { if (!wi.disabled) renderWh(wi.value); });
    wi.addEventListener('input', () => renderWh(wi.value));
    wm.addEventListener('click', (e) => {
      const o = e.target.closest('.np-opt[data-ref]'); if (!o) return;
      pickWh(o.dataset.ref, o.dataset.name);
    });
    wi.addEventListener('keydown', (e) => keyNav(wm, e));

    document.addEventListener('click', (e) => {
      if (!wrap.contains(e.target)) { closeMenu(cm); closeMenu(wm); }
    });
  };

  const restore = () => {
    api(cfg.getSelection).then((d) => {
      if (!d || !d.city_ref) return;
      cityRef = d.city_ref; cityName = d.city_name || '';
      cityInput().value = cityName;
      const w = whInput(); w.disabled = false; w.placeholder = t.whReady;
      api(cfg.getWarehouses, { city_ref: cityRef }).then((r) => { warehouses = (r && r.warehouses) || []; });
      if (d.warehouse_ref) {
        whHidden().value = d.warehouse_ref;
        whHidden().dataset.name = d.warehouse_name || '';
        w.value = d.warehouse_name || '';
        renderSummary();
        // Re-persist into the session so a fresh checkout that merely *restores*
        // a prior choice (e.g. after login/guest save) still carries the NP
        // selection into the order on confirm.
        api(cfg.setSelection, { city_ref: cityRef, city_name: cityName, warehouse_ref: d.warehouse_ref, warehouse_name: d.warehouse_name || '' });
      }
      fillNativeAddress();
    }).catch(() => {});
  };

  // --- method-first gating: show the widget only after the Nova Poshta
  // shipping method is chosen in the theme (input[name="shipping_method"]).
  const selectedShippingCode = () =>
    (document.querySelector('#input-shipping-code')?.value
      || document.querySelector('input[name="shipping_method"]:checked')?.value
      || '');
  const gate = () => {
    const isNp = selectedShippingCode().indexOf('nova_poshta.') === 0;
    wrap.classList.toggle('np-gated', !isNp);
  };
  const wireGate = () => {
    document.addEventListener('change', (e) => {
      if (e.target && e.target.name === 'shipping_method') gate();
    });
    const codeEl = document.querySelector('#input-shipping-code') || document.querySelector('#input-shipping-method');
    if (codeEl) new MutationObserver(gate).observe(codeEl, { attributes: true, attributeFilter: ['value'] });
    let n = 0; const iv = setInterval(() => { gate(); if (++n > 60) clearInterval(iv); }, 500);
  };
  const seedNative = () => {
    const country = q1(NATIVE.country);
    if (country && country.value !== '220') {
      const opt = [...country.options].find((o) => /Україна|Ukraine/i.test(o.text));
      if (opt) { country.value = opt.value; country.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    setTimeout(() => {
      const zone = q1(NATIVE.zone);
      if (zone && !zone.value) {
        const opt = [...zone.options].find((o) => o.value);
        if (opt) { zone.value = opt.value; zone.dispatchEvent(new Event('change', { bubbles: true })); }
      }
      if (!q1(NATIVE.city)?.value)     setVal(q1(NATIVE.city), '—');
      if (!q1(NATIVE.postcode)?.value) setVal(q1(NATIVE.postcode), '00000');
    }, 500);
  };

  const init = () => {
    if (window.__npPickerMounted) return;
    if (!mount()) return;
    window.__npPickerMounted = true;
    wrap.classList.add('np-gated'); // hidden until the Nova Poshta method is chosen
    hideNativeAddress();
    bind();
    seedNative();
    wireGate();
    gate();
    restore();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
