/* password-ui.js — eye toggle on every password box, plus a live strength
   checklist for inputs marked data-strength="1".
   Load after store.js (the rules come from GMS.validate.passwordRules, which
   mirrors password_rule_missing() in config/db.php). */
(function () {
  const EYE_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.7-6.5 10-6.5S22 12 22 12s-3.7 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>';
  const EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.7-6.5 10-6.5S22 12 22 12s-3.7 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/><path d="M3 3l18 18"/></svg>';

  const ITEMS = [
    { key: 'length', label: '8\u201372 characters' },
    { key: 'upper', label: 'Uppercase letter' },
    { key: 'lower', label: 'Lowercase letter' },
    { key: 'digit', label: 'Number' },
    { key: 'symbol', label: 'Symbol (! @ # $ %)' },
  ];

  function rules(value) {
    if (typeof GMS !== 'undefined' && GMS.validate && GMS.validate.passwordRules) {
      return GMS.validate.passwordRules(value);
    }
    const s = String(value == null ? '' : value);
    return {
      length: s.length >= 8 && s.length <= 72,
      upper: /[A-Z]/.test(s),
      lower: /[a-z]/.test(s),
      digit: /[0-9]/.test(s),
      symbol: /[^A-Za-z0-9]/.test(s),
    };
  }

  const updates = [];

  function makeMeter(input) {
    const meter = document.createElement('div');
    meter.className = 'pw-meter';
    meter.innerHTML = '<div class="pw-bar"><i></i></div><ul class="pw-rules">' +
      ITEMS.map(function (item) {
        return '<li data-rule="' + item.key + '">' + item.label + '</li>';
      }).join('') + '</ul>';
    input.closest('.pw-wrap').insertAdjacentElement('afterend', meter);

    const bar = meter.querySelector('.pw-bar i');
    const rows = {};
    meter.querySelectorAll('li').forEach(function (li) { rows[li.getAttribute('data-rule')] = li; });

    const update = function () {
      const r = rules(input.value);
      let met = 0;
      ITEMS.forEach(function (item) {
        const ok = !!r[item.key];
        if (ok) met++;
        rows[item.key].classList.toggle('ok', ok);
      });
      bar.style.width = Math.round(met / ITEMS.length * 100) + '%';
      meter.classList.remove('lvl-low', 'lvl-mid', 'lvl-ok');
      meter.classList.add(met === ITEMS.length ? 'lvl-ok' : met >= 3 ? 'lvl-mid' : 'lvl-low');
    };

    input.addEventListener('input', update);
    updates.push(update);
    update();
  }

  function enhance(input) {
    if (input.getAttribute('data-pw-ready') === '1') return;
    input.setAttribute('data-pw-ready', '1');

    const wrap = document.createElement('div');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-eye';
    btn.innerHTML = EYE_OPEN;
    btn.setAttribute('aria-label', 'Show password');
    btn.title = 'Show password';
    btn.addEventListener('click', function () {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? EYE_OFF : EYE_OPEN;
      btn.classList.toggle('is-on', show);
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.title = show ? 'Hide password' : 'Show password';
      input.focus({ preventScroll: true });
    });
    wrap.appendChild(btn);

    if (input.hasAttribute('data-strength')) makeMeter(input);
  }

  function enhanceAll() {
    document.querySelectorAll('input[type="password"]').forEach(enhance);
  }

  function refreshAll() {
    updates.forEach(function (update) { update(); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhanceAll);
  else enhanceAll();

  window.GMSPassword = { enhanceAll: enhanceAll, refreshAll: refreshAll, rules: rules };
})();
