(() => {
  const cfg = window.__novaPoshtaPremium;
  if (!cfg) return;

  // Only activate on a route that looks like checkout.
  const route = new URLSearchParams(location.search).get('route') || '';
  if (!/^checkout/.test(route)) return;

  const wrap = document.createElement('div');
  wrap.id = 'np-picker';
  wrap.style.cssText = 'border:1px solid #d9534f;background:#fff8f5;border-radius:6px;padding:12px;margin:12px 0;font-family:Arial,sans-serif;';
  wrap.innerHTML = `
    <div style="font-weight:bold;color:#d9534f;margin-bottom:6px;">Доставка Новою Поштою</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
      <div style="flex:1;min-width:200px;">
        <label style="display:block;font-size:12px;color:#555;">Місто</label>
        <div style="display:flex;gap:4px;">
          <input id="np-city-q" type="text" placeholder="Введіть назву міста..." style="flex:1;padding:6px;border:1px solid #ccc;border-radius:4px;"/>
          <button id="np-city-search" type="button" style="padding:6px 10px;background:#d9534f;color:#fff;border:0;border-radius:4px;cursor:pointer;">Знайти</button>
        </div>
        <div id="np-city-results" style="max-height:140px;overflow-y:auto;border:1px solid #eee;border-radius:4px;margin-top:4px;display:none;"></div>
        <div id="np-city-selected" style="font-size:12px;color:#2a7c2a;margin-top:4px;"></div>
      </div>
      <div style="flex:1;min-width:200px;">
        <label style="display:block;font-size:12px;color:#555;">Відділення</label>
        <select id="np-warehouse" style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;">
          <option value="">— оберіть місто —</option>
        </select>
        <div id="np-wh-selected" style="font-size:12px;color:#2a7c2a;margin-top:4px;"></div>
      </div>
    </div>
  `;

  function mount() {
    const anchors = ['#shipping_method', '#payment_method', '#collapse-shipping-method', 'main', 'body'];
    for (const sel of anchors) {
      const el = document.querySelector(sel);
      if (el) { el.parentNode.insertBefore(wrap, el); return; }
    }
  }

  function api(url, body) {
    const opts = { method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'}, credentials: 'same-origin' };
    if (body) opts.body = new URLSearchParams(body);
    return fetch(url, opts).then(r => r.json());
  }

  let selectedCityRef = '';
  let selectedCityName = '';

  function loadWarehouses(cityRef) {
    const sel = document.getElementById('np-warehouse');
    sel.innerHTML = '<option value="">...</option>';
    api(cfg.getWarehouses, {city_ref: cityRef}).then(d => {
      sel.innerHTML = '<option value="">—</option>';
      (d.warehouses || []).forEach(w => {
        const opt = document.createElement('option');
        opt.value = w.ref;
        opt.dataset.name = w.description;
        opt.textContent = w.description;
        sel.appendChild(opt);
      });
    });
  }

  function persist() {
    const sel = document.getElementById('np-warehouse');
    const whRef = sel.value;
    const whName = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.name || '' : '';
    document.getElementById('np-wh-selected').textContent = whName ? '✓ ' + whName : '';
    api(cfg.setSelection, {
      city_ref: selectedCityRef,
      city_name: selectedCityName,
      warehouse_ref: whRef,
      warehouse_name: whName
    });
  }

  function bind() {
    const btn = document.getElementById('np-city-search');
    const q = document.getElementById('np-city-q');
    const results = document.getElementById('np-city-results');

    btn.addEventListener('click', () => {
      const val = q.value.trim();
      if (!val) return;
      results.innerHTML = '<div style="padding:6px;color:#888;">...</div>';
      results.style.display = 'block';
      api(cfg.searchCities, {q: val}).then(d => {
        results.innerHTML = '';
        if (!d.cities || !d.cities.length) {
          results.innerHTML = '<div style="padding:6px;color:#888;">no results</div>';
          return;
        }
        d.cities.forEach(c => {
          const item = document.createElement('div');
          item.style.cssText = 'padding:6px;cursor:pointer;border-bottom:1px solid #eee;';
          item.textContent = c.name + (c.area ? ' (' + c.area + ')' : '');
          item.addEventListener('click', () => {
            selectedCityRef = c.ref;
            selectedCityName = c.name;
            document.getElementById('np-city-selected').textContent = '✓ ' + c.name;
            results.style.display = 'none';
            q.value = c.name;
            loadWarehouses(c.ref);
          });
          results.appendChild(item);
        });
      });
    });

    q.addEventListener('keypress', e => { if (e.key === 'Enter') { e.preventDefault(); btn.click(); } });
    document.getElementById('np-warehouse').addEventListener('change', persist);
  }

  function restore() {
    api(cfg.getSelection).then(d => {
      if (d.city_ref) {
        selectedCityRef = d.city_ref;
        selectedCityName = d.city_name;
        document.getElementById('np-city-q').value = d.city_name;
        document.getElementById('np-city-selected').textContent = '✓ ' + d.city_name;
        loadWarehouses(d.city_ref);
        if (d.warehouse_name) {
          document.getElementById('np-wh-selected').textContent = '✓ ' + d.warehouse_name;
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { mount(); bind(); restore(); });
  } else {
    mount(); bind(); restore();
  }
})();
