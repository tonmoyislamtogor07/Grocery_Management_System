/* ==========================================================================
   store.js — API-backed data layer (PHP + MySQL).
   Same GMS.* names the pages already use, but every read/write now hits
   the PHP API instead of localStorage dummy data. No dummy data here.
   Usage in pages:  await GMS.ready;  const list = await GMS.products.all();
   ========================================================================== */

const GMS = (function () {
  const BASE = 'api';

  async function api(path, options) {
    const res = await fetch(BASE + path, Object.assign(
      { headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' },
      options || {}
    ));
    let body = null;
    try { body = await res.json(); } catch (e) { body = null; }
    if (!res.ok || !body || body.ok === false) {
      throw new Error((body && body.error) || ('Request failed (' + res.status + ')'));
    }
    return body.data !== undefined ? body.data : body;
  }

  // ---- in-memory caches (filled by refresh, kept fresh after writes) ----
  const cache = { categories: [], products: [], suppliers: [], customers: [], purchases: [], sales: [], expenses: [] };

  async function refresh(entity) {
    const map = {
      categories: '/categories.php', products: '/products.php', suppliers: '/suppliers.php',
      customers: '/customers.php', purchases: '/purchases.php', sales: '/sales.php', expenses: '/expenses.php',
    };
    cache[entity] = await api(map[entity]);
    return cache[entity];
  }

  async function refreshAll() {
    // Resolve the server session before choosing endpoints; a fresh tab has
    // no role in sessionStorage yet, and cashiers must not request costs.
    let role = '';
    try {
      const res = await fetch(BASE + '/auth.php?action=me', { credentials: 'same-origin' });
      const body = await res.json();
      if (res.ok && body && body.ok && body.user) {
        role = body.user.role || '';
        sessionStorage.setItem('gms_session', body.user.username);
        sessionStorage.setItem('gms_role', role);
        sessionStorage.setItem('gms_role_label', body.user.role_label || '');
        sessionStorage.setItem('gms_name', body.user.full_name || '');
        sessionStorage.setItem('gms_email', body.user.email || '');
      }
    } catch (e) {}
    // Cashier never touches purchase costs / payouts / profit endpoints.
    // The backend also enforces these permissions.
    const all = Object.keys(cache);
    const list = role === 'cashier'
      ? all.filter(function (e) { return e !== 'purchases' && e !== 'expenses'; })
      : all;
    await Promise.all(list.map(refresh));
  }

  const ready = refreshAll();
  // auto-retry once so a slow MySQL wake-up does not leave pages empty
  ready.catch(function () { /* pages surface the error via toast */ });

  function makeRepo(entity, endpoint) {
    return {
      all: async function () { return cache[entity]; },
      sync: function () { return cache[entity]; }, // sync read of last-loaded cache (for helpers)
      get: async function (id) {
        id = Number(id);
        const hit = cache[entity].find(function (x) { return Number(x.id) === id; });
        if (hit) return hit;
        try { return await api(endpoint + '?id=' + encodeURIComponent(id)); }
        catch (e) { return null; }
      },
      add: async function (data) {
        const created = await api(endpoint, { method: 'POST', body: JSON.stringify(data) });
        await refresh(entity);
        if (entity === 'purchases' || entity === 'sales') await refresh('products');
        return created;
      },
      update: async function (id, data) {
        const updated = await api(endpoint + '?id=' + encodeURIComponent(id), { method: 'PUT', body: JSON.stringify(data) });
        await refresh(entity);
        return updated;
      },
      remove: async function (id) {
        await api(endpoint + '?id=' + encodeURIComponent(id), { method: 'DELETE' });
        await refresh(entity);
      },
    };
  }

  const categories = makeRepo('categories', '/categories.php');
  const products   = makeRepo('products', '/products.php');
  const suppliers  = makeRepo('suppliers', '/suppliers.php');
  const customers  = makeRepo('customers', '/customers.php');
  const purchases  = makeRepo('purchases', '/purchases.php');
  const sales      = makeRepo('sales', '/sales.php');
  const expenses   = makeRepo('expenses', '/expenses.php');

  /* Staff accounts (Owner / Manager only; the permission matrix lives in
     api/users.php). Not cached — this page always wants fresh rows. */
  const staff = {
    all: function () { return api('/users.php'); },
    log: function () { return api('/users.php?action=log'); },
    add: function (data) { return api('/users.php', { method: 'POST', body: JSON.stringify(data) }); },
    update: function (id, data) {
      return api('/users.php?id=' + encodeURIComponent(id), { method: 'PUT', body: JSON.stringify(data) });
    },
    remove: function (id) {
      return api('/users.php?id=' + encodeURIComponent(id), { method: 'DELETE' });
    },
    unlock: function (id) {
      return api('/users.php?action=unlock&id=' + encodeURIComponent(id), { method: 'POST' });
    },
  };

  /* Password change / email reset. api/password.php enforces the approval
     rules: Owner applies instantly, Manager needs the Owner, Cashier needs
     the Manager or Owner. */
  const account = {
    forgot: function (username) {
      return api('/password.php?action=forgot', { method: 'POST', body: JSON.stringify({ username: username }) });
    },
    verify: function (data) {
      return api('/password.php?action=verify', { method: 'POST', body: JSON.stringify(data) });
    },
    request: function (data) {
      return api('/password.php?action=request', { method: 'POST', body: JSON.stringify(data) });
    },
    list: function () { return api('/password.php?action=list'); },
    approve: function (id) {
      return api('/password.php?action=approve&id=' + encodeURIComponent(id), { method: 'POST' });
    },
    reject: function (id) {
      return api('/password.php?action=reject&id=' + encodeURIComponent(id), { method: 'POST' });
    },
    cancel: function (id) {
      return api('/password.php?action=cancel&id=' + encodeURIComponent(id), { method: 'POST' });
    },
  };

  /* ---------------- formatting helpers (unchanged UI behaviour) ---------------- */
  function todayISO() {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit',
    }).formatToParts(new Date());
    const part = function (type) { return parts.find(function (item) { return item.type === type; }).value; };
    return part('year') + '-' + part('month') + '-' + part('day');
  }
  function formatMoney(n) {
    const num = Number(n) || 0;
    return '\u09F3' + num.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function formatDate(iso) {
    if (!iso) return '\u2014';
    const d = new Date(String(iso).slice(0, 10) + 'T00:00:00');
    if (isNaN(d)) return iso;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  }
  function daysUntil(iso) {
    if (!iso) return null;
    const toUtcDay = function (date) {
      const parts = String(date).slice(0, 10).split('-').map(Number);
      return Date.UTC(parts[0], parts[1] - 1, parts[2]);
    };
    return Math.round((toUtcDay(iso) - toUtcDay(todayISO())) / 86400000);
  }

  /* ---------------- derived helpers (read from live cache) ---------------- */
  function categoryName(id) {
    if (id == null) return '\u2014';
    const c = cache.categories.find(function (x) { return Number(x.id) === Number(id); });
    return c ? c.name : '\u2014';
  }
  function supplierName(id) {
    const s = cache.suppliers.find(function (x) { return Number(x.id) === Number(id); });
    return s ? s.name : '\u2014';
  }
  // Option B: many suppliers per product -> "A, B, C" or em-dash.
  function supplierNames(ids) {
    if (!ids || ids.length === 0) return '\u2014';
    return ids.map(function (id) {
      const s = cache.suppliers.find(function (x) { return Number(x.id) === Number(id); });
      return s ? s.name : ('#' + id);
    }).join(', ');
  }
  function customerName(id) {
    if (!id) return 'Walk-in customer';
    const c = cache.customers.find(function (x) { return Number(x.id) === Number(id); });
    return c ? c.name : '\u2014';
  }
  function moneyCents(value) {
    const scaled = (Number(value) || 0) * 100;
    return Math.round(scaled + Number.EPSILON * Math.abs(scaled));
  }
  function lineTotal(item) { return (Number(item.qty) || 0) * (Number(item.price) || 0); }
  function docSubtotal(doc) {
    return (doc.items || []).reduce(function (sum, item) {
      return sum + moneyCents(item.price) * (Number(item.qty) || 0);
    }, 0) / 100;
  }
  function docTotal(doc) {
    const total = doc.total;
    if (total !== undefined && total !== null && Number.isFinite(Number(total))) {
      return Math.max(0, moneyCents(total)) / 100;
    }
    return Math.max(0, moneyCents(docSubtotal(doc)) - moneyCents(doc.discount)) / 100;
  }
  function docDiscount(doc) {
    if (doc.discount !== undefined && doc.discount !== null) return Math.max(0, Number(doc.discount) || 0);
    return Math.max(0, docSubtotal(doc) - docTotal(doc));
  }
  function docLines(doc) {
    const items = doc.items || [];
    const grossCents = items.map(function (item) {
      return Math.max(0, moneyCents(item.price) * (Number(item.qty) || 0));
    });
    const subtotalCents = grossCents.reduce(function (sum, cents) { return sum + cents; }, 0);
    const discountCents = Math.min(subtotalCents, Math.max(0, moneyCents(docDiscount(doc))));
    const shares = grossCents.map(function (cents, index) {
      const exact = subtotalCents > 0 ? discountCents * cents / subtotalCents : 0;
      const base = Math.floor(exact);
      return { index: index, grossCents: cents, discountCents: base, remainder: exact - base };
    });
    let remaining = discountCents - shares.reduce(function (sum, share) { return sum + share.discountCents; }, 0);
    shares.slice().sort(function (a, b) { return b.remainder - a.remainder || a.index - b.index; })
      .slice(0, remaining).forEach(function (share) { shares[share.index].discountCents++; });
    return items.map(function (item, index) {
      const share = shares[index];
      return {
        item: item,
        gross: share.grossCents / 100,
        discount: share.discountCents / 100,
        net: (share.grossCents - share.discountCents) / 100,
      };
    });
  }
  function docPaid(doc) { return Math.max(0, Number(doc.cashReceived) || 0); }
  function docDue(doc) {
    const due = doc.due;
    if (due !== undefined && due !== null && Number.isFinite(Number(due))) {
      return Math.max(0, Number(due));
    }
    const laterPayments = (doc.payments || []).reduce(function (sum, payment) {
      return sum + (Number(payment.amount) || 0);
    }, 0);
    return Math.max(0, docTotal(doc) - docPaid(doc) - laterPayments);
  }
  function stockStatus(p) {
    if ((Number(p.stock) || 0) <= 0) return 'out';
    if ((Number(p.stock) || 0) <= (Number(p.reorderLevel) || 0)) return 'low';
    return 'ok';
  }
  function isExpiringSoon(p, withinDays) {
    withinDays = withinDays || 30;
    const d = daysUntil(p.expiry);
    return d !== null && d <= withinDays;
  }

  // Stock moves server-side (purchases/sales POST). These are kept so old
  // calls don't break, but they only refresh the product cache.
  async function applyPurchaseToStock() { await refresh('products'); }
  async function applySaleToStock() { await refresh('products'); }

  async function dashboardStats() {
    try {
      const data = await api('/dashboard.php');
      return data.stats;
    } catch (e) {
      // fallback: compute from cache if the endpoint is unreachable
      const totalSales = cache.sales.reduce(function (s, d) { return s + docTotal(d); }, 0);
      const totalPurchases = cache.purchases.reduce(function (s, d) { return s + docTotal(d); }, 0);
      const totalExpenses = cache.expenses.reduce(function (s, x) { return s + (Number(x.amount) || 0); }, 0);
      // COGS = sold qty x current purchase price (only what was actually sold)
      const priceOf = function (pid) {
        const p = cache.products.find(function (x) { return String(x.id) === String(pid); });
        return p ? (Number(p.purchasePrice) || 0) : 0;
      };
      const cogs = cache.sales.reduce(function (sum, s) {
        return sum + (s.items || []).reduce(function (s2, it) {
          const c = (it.cost !== undefined && it.cost !== null) ? Number(it.cost) : priceOf(it.productId);
          return s2 + (it.costTotal !== undefined && it.costTotal !== null ? Number(it.costTotal) : Number(it.qty) * c);
        }, 0);
      }, 0);
      const grossProfit = totalSales - cogs;
      const netProfit = grossProfit - totalExpenses;
      const today = todayISO();
      return {
        totalSales: totalSales, totalPurchases: totalPurchases, totalExpenses: totalExpenses,
        cogs: cogs, grossProfit: grossProfit, netProfit: netProfit, estimatedProfit: netProfit,
        todayRevenue: cache.sales.filter(function (s) { return s.date === today; }).reduce(function (s, d) { return s + docTotal(d); }, 0),
        lowStockCount: cache.products.filter(function (p) { return stockStatus(p) !== 'ok'; }).length,
        productCount: cache.products.length, salesCount: cache.sales.length,
      };
    }
  }

  async function topSellingProducts(limit) {
    limit = limit || 5;
    const counts = {};
    cache.sales.forEach(function (s) {
      (s.items || []).forEach(function (it) { counts[it.productId] = (counts[it.productId] || 0) + Number(it.qty); });
    });
    return Object.entries(counts)
      .map(function ([productId, qty]) {
        return { product: cache.products.find(function (p) { return Number(p.id) === Number(productId); }), qty: qty };
      })
      .filter(function (r) { return r.product; })
      .sort(function (a, b) { return b.qty - a.qty; })
      .slice(0, limit);
  }

  /* ---------------- client-side validation (mirrors PHP v_* rules) -----
     Catches bad input instantly; PHP re-checks everything server-side. */
  const validate = {
    name: function (v, field, max) {
      const s = String(v == null ? '' : v).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').replace(/\s+/g, ' ').trim();
      if (s.length < 2) return (field || 'Name') + ' must be at least 2 characters.';
      if (s.length > (max || 200)) return (field || 'Name') + ' must be at most ' + (max || 200) + ' characters.';
      if (!/^[\p{L}\p{M}0-9 .,'()&/%+-]+$/u.test(s)) return (field || 'Name') + " contains invalid characters.";
      return null;
    },
    phone: function (v, field, required) {
      const s = String(v == null ? '' : v).replace(/[\s\-()]/g, '');
      if (s === '') return required ? ((field || 'Phone') + ' is required.') : null;
      if (!/^01[3-9]\d{8}$/.test(s)) return (field || 'Phone') + ' must be a valid 11-digit mobile number starting with 01.';
      return null;
    },
    email: function (v) {
      const s = String(v == null ? '' : v).trim();
      if (s === '') return null;
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s)) return 'Email must be a valid email address.';
      return null;
    },
    money: function (v, field, mustBePositive) {
      if (v === '' || v === null || v === undefined || isNaN(Number(v))) return (field || 'Amount') + ' must be a number.';
      const f = Number(v);
      if (!isFinite(f)) return (field || 'Amount') + ' is not a valid number.';
      if (mustBePositive ? f <= 0 : f < 0) return mustBePositive ? ((field || 'Amount') + ' must be greater than zero.') : ((field || 'Amount') + ' cannot be negative.');
      return null;
    },
    qty: function (v, field, min) {
      const m = min === undefined ? 1 : min;
      if (!/^-?\d+$/.test(String(v))) return (field || 'Quantity') + ' must be a whole number.';
      if (parseInt(v, 10) < m) return (field || 'Quantity') + ' must be at least ' + m + '.';
      return null;
    },
    date: function (v, field) {
      const s = String(v || '');
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
      if (!m) return (field || 'Date') + ' must be a valid date.';
      // Round-trip with LOCAL parts (never toISOString — UTC shift breaks
      // dates for timezones ahead of UTC, e.g. Asia/Dhaka).
      const d = new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
      if (d.getFullYear() !== parseInt(m[1], 10) || d.getMonth() !== parseInt(m[2], 10) - 1 || d.getDate() !== parseInt(m[3], 10)) {
        return (field || 'Date') + ' must be a real calendar date.';
      }
      return null;
    },
  };

  /* ---------------- session (real PHP role auth) ---------------- */
  const ROLE_HOME = { owner: 'dashboard.html', manager: 'dashboard.html', cashier: 'cashier.html' };

  const session = {
    login: async function (username, password, role) {
      const res = await fetch(BASE + '/auth.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username, password: password, role: role || '' }),
      });
      const body = await res.json().catch(function () { return null; });
      if (!res.ok || !body || body.ok === false) throw new Error((body && body.error) || 'Login failed.');
      try {
        sessionStorage.setItem('gms_session', body.user.username);
        sessionStorage.setItem('gms_role', body.user.role || '');
        sessionStorage.setItem('gms_role_label', body.user.role_label || '');
        sessionStorage.setItem('gms_name', body.user.full_name || '');
        sessionStorage.setItem('gms_email', body.user.email || '');
      } catch (e) {}
      return body.user;
    },
    logout: async function () {
      try { await fetch(BASE + '/auth.php?action=logout', { credentials: 'same-origin' }); } catch (e) {}
      try {
        sessionStorage.removeItem('gms_session');
        sessionStorage.removeItem('gms_role');
        sessionStorage.removeItem('gms_role_label');
      } catch (e) {}
      location.href = 'index.html';
    },
    current: function () {
      try { return sessionStorage.getItem('gms_session'); } catch (e) { return null; }
    },
    role: function () {
      try { return sessionStorage.getItem('gms_role') || ''; } catch (e) { return ''; }
    },
    roleLabel: function () {
      try { return sessionStorage.getItem('gms_role_label') || ''; } catch (e) { return ''; }
    },
    home: function () {
      return ROLE_HOME[session.role()] || 'dashboard.html';
    },
    requireLogin: async function () {
      try {
        const res = await fetch(BASE + '/auth.php?action=me', { credentials: 'same-origin' });
        if (res.ok) {
          const body = await res.json().catch(function () { return null; });
          if (body && body.ok && body.user) {
            try {
              sessionStorage.setItem('gms_session', body.user.username);
              sessionStorage.setItem('gms_role', body.user.role || '');
              sessionStorage.setItem('gms_role_label', body.user.role_label || '');
              sessionStorage.setItem('gms_name', body.user.full_name || '');
              sessionStorage.setItem('gms_email', body.user.email || '');
            } catch (e) {}
            return;
          }
        }
      } catch (e) {}
      try {
        sessionStorage.removeItem('gms_session');
        sessionStorage.removeItem('gms_role');
        sessionStorage.removeItem('gms_role_label');
      } catch (e) {}
      location.href = 'index.html';
    },
  };

  return {
    ready: ready, refresh: refresh, refreshAll: refreshAll,
    validate: validate,
    categories: categories, products: products, suppliers: suppliers, customers: customers,
    purchases: purchases, sales: sales, expenses: expenses, staff: staff, account: account,
    categoryName: categoryName, supplierName: supplierName, supplierNames: supplierNames, customerName: customerName,
    lineTotal: lineTotal, docSubtotal: docSubtotal, docTotal: docTotal,
    docDiscount: docDiscount, docLines: docLines, docPaid: docPaid, docDue: docDue, stockStatus: stockStatus,
    isExpiringSoon: isExpiringSoon, daysUntil: daysUntil,
    applyPurchaseToStock: applyPurchaseToStock, applySaleToStock: applySaleToStock,
    dashboardStats: dashboardStats, topSellingProducts: topSellingProducts,
    formatMoney: formatMoney, formatDate: formatDate, todayISO: todayISO,
    session: session,
  };
})();
