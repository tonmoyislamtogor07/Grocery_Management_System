document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  // Sync role from the PHP session first (fresh browser tab may not have it
  // in sessionStorage yet), then load data — retry once so a cashier who
  // typed this URL directly still gets the correct limited entity list.
  try { await GMS.session.requireLogin(); } catch (e) {}
  try { await GMS.ready; } catch (e) {
    try { await GMS.refreshAll(); }
    catch (e2) { GMSApp.showToast('Database error: ' + e2.message, true); return; }
  }

  let activeTab = 'sales';
  const TABS_WITH_PERIOD = ['sales', 'purchases', 'profit'];
  // Cashier gets a limited Reports view: own-counter info only (bills,
  // top sellers, stock/expiry). Profit, purchases, supplier costs, expenses
  // and Insights stay hidden (backend also blocks those endpoints with 403).
  const CASHIER_TABS = ['sales', 'topselling', 'inventory'];
  const isCashierView = (typeof GMS !== 'undefined' && GMS.session.role() === 'cashier');
  if (isCashierView) {
    activeTab = 'sales';
    document.querySelectorAll('#tabBar button[data-tab]').forEach(function (b) {
      if (CASHIER_TABS.indexOf(b.getAttribute('data-tab')) === -1) b.remove();
      else {
        b.classList.toggle('btn-primary', b.getAttribute('data-tab') === 'sales');
        b.classList.toggle('btn-ghost', b.getAttribute('data-tab') !== 'sales');
      }
    });
  }

  function periodKey(dateStr, period) {
    if (period === 'daily') return dateStr;
    if (period === 'monthly') return String(dateStr).slice(0, 7);
    if (period === 'yearly') return String(dateStr).slice(0, 4);
    return 'All time';
  }

  function periodLabel(key, period) {
    if (period === 'all') return 'All time';
    if (period === 'yearly') return key;
    if (period === 'monthly') {
      const d = new Date(key + '-01T00:00:00');
      return d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    }
    return GMS.formatDate(key);
  }

  function groupTotals(docs, period, valueFn) {
    const map = {};
    docs.forEach(function (d) {
      const key = periodKey(d.date, period);
      map[key] = (map[key] || 0) + valueFn(d);
    });
    return map;
  }

  async function renderSales(period) {
    const sales = await GMS.sales.all();
    const totals = groupTotals(sales, period, function (s) { return GMS.docTotal(s); });
    const counts = {};
    sales.forEach(function (s) { const k = periodKey(s.date, period); counts[k] = (counts[k] || 0) + 1; });

    const keys = Object.keys(totals).sort().reverse();
    const grand = Object.values(totals).reduce(function (a, b) { return a + b; }, 0);

    let html = '<div class="stat-row stat-row--spaced">' +
      '<div class="stat accent-green"><div class="label">Total sales revenue</div><div class="value">' + GMS.formatMoney(grand) + '</div></div>' +
      '<div class="stat accent-green"><div class="label">Number of receipts</div><div class="value">' + sales.length + '</div></div>' +
      '</div>';

    html += '<div class="table-wrap"><table><thead><tr><th>Period</th><th class="cell-num">Receipts</th><th class="cell-num">Revenue</th></tr></thead><tbody>';
    html += keys.length === 0
      ? '<tr class="empty-row"><td colspan="3">No sales recorded yet.</td></tr>'
      : keys.map(function (k) { return '<tr><td>' + periodLabel(k, period) + '</td><td class="cell-num">' + counts[k] + '</td><td class="cell-num">' + GMS.formatMoney(totals[k]) + '</td></tr>'; }).join('');
    html += '</tbody></table></div>';
    return html;
  }

  async function renderPurchases(period) {
    const purchases = await GMS.purchases.all();
    const totals = groupTotals(purchases, period, function (p) { return GMS.docTotal(p); });
    const counts = {};
    purchases.forEach(function (p) { const k = periodKey(p.date, period); counts[k] = (counts[k] || 0) + 1; });

    const keys = Object.keys(totals).sort().reverse();
    const grand = Object.values(totals).reduce(function (a, b) { return a + b; }, 0);

    let html = '<div class="stat-row stat-row--spaced">' +
      '<div class="stat accent-turmeric"><div class="label">Total spent on purchases</div><div class="value">' + GMS.formatMoney(grand) + '</div></div>' +
      '<div class="stat accent-turmeric"><div class="label">Number of purchase orders</div><div class="value">' + purchases.length + '</div></div>' +
      '</div>';

    html += '<div class="table-wrap"><table><thead><tr><th>Period</th><th class="cell-num">Orders</th><th class="cell-num">Amount</th></tr></thead><tbody>';
    html += keys.length === 0
      ? '<tr class="empty-row"><td colspan="3">No purchases recorded yet.</td></tr>'
      : keys.map(function (k) { return '<tr><td>' + periodLabel(k, period) + '</td><td class="cell-num">' + counts[k] + '</td><td class="cell-num">' + GMS.formatMoney(totals[k]) + '</td></tr>'; }).join('');
    html += '</tbody></table></div>';
    return html;
  }

  async function renderProfit(period) {
    const sales = await GMS.sales.all();
    const products = await GMS.products.all();
    const expenses = await GMS.expenses.all();

    const costOf = function (pid) {
      const p = products.find(function (x) { return String(x.id) === String(pid); });
      return p ? (Number(p.purchasePrice) || 0) : 0;
    };
    const saleCogs = function (s) {
      return (s.items || []).reduce(function (sum, it) {
        // prefer the cost snapshot stored on the bill; fall back to current cost
        const c = (it.cost !== undefined && it.cost !== null) ? Number(it.cost) : costOf(it.productId);
        return sum + Number(it.qty) * c;
      }, 0);
    };

    const salesTotals = groupTotals(sales, period, function (s) { return GMS.docTotal(s); });
    const cogsTotals = groupTotals(sales, period, saleCogs);
    const expenseTotals = groupTotals(expenses, period, function (e) { return Number(e.amount) || 0; });

    const keys = Array.from(new Set([].concat(Object.keys(salesTotals), Object.keys(cogsTotals), Object.keys(expenseTotals)))).sort().reverse();

    const totalSales = Object.values(salesTotals).reduce(function (a, b) { return a + b; }, 0);
    const totalCogs = Object.values(cogsTotals).reduce(function (a, b) { return a + b; }, 0);
    const grossProfit = totalSales - totalCogs;
    const totalExpenses = Object.values(expenseTotals).reduce(function (a, b) { return a + b; }, 0);
    const netProfit = grossProfit - totalExpenses;

    let html = '<div class="stat-row stat-row--spaced">' +
      '<div class="stat accent-green"><div class="label">Sales revenue</div><div class="value">' + GMS.formatMoney(totalSales) + '</div></div>' +
      '<div class="stat accent-turmeric"><div class="label">Cost of goods sold</div><div class="value">' + GMS.formatMoney(totalCogs) + '</div></div>' +
      '<div class="stat ' + (grossProfit < 0 ? 'accent-red' : 'accent-green') + '"><div class="label">Gross profit</div><div class="value' + (grossProfit < 0 ? ' neg' : '') + '">' + GMS.formatMoney(grossProfit) + '</div></div>' +
      '<div class="stat accent-turmeric"><div class="label">Expenses</div><div class="value">' + GMS.formatMoney(totalExpenses) + '</div></div>' +
      '<div class="stat ' + (netProfit < 0 ? 'accent-red' : 'accent-green') + '"><div class="label">Net profit</div><div class="value' + (netProfit < 0 ? ' neg' : '') + '">' + GMS.formatMoney(netProfit) + '</div></div>' +
      '</div>';
    html += '<div class="text-muted report-note">Gross = sales &minus; cost of sold goods only (unsold stock is not subtracted). Net = gross &minus; expenses.</div>';

    html += '<div class="table-wrap"><table><thead><tr><th>Period</th><th class="cell-num">Sales</th><th class="cell-num">COGS</th><th class="cell-num">Gross</th><th class="cell-num">Expenses</th><th class="cell-num">Net</th></tr></thead><tbody>';
    html += keys.length === 0
      ? '<tr class="empty-row"><td colspan="6">Not enough data yet to calculate profit.</td></tr>'
      : keys.map(function (k) {
          const s = salesTotals[k] || 0, c = cogsTotals[k] || 0, e = expenseTotals[k] || 0;
          const gross = s - c, net = gross - e;
          return '<tr><td>' + periodLabel(k, period) + '</td><td class="cell-num">' + GMS.formatMoney(s) + '</td><td class="cell-num">' + GMS.formatMoney(c) + '</td><td class="cell-num ' + (gross < 0 ? 'profit-neg' : 'profit-pos') + '">' + GMS.formatMoney(gross) + '</td><td class="cell-num">' + GMS.formatMoney(e) + '</td><td class="cell-num ' + (net < 0 ? 'profit-neg' : 'profit-pos') + '">' + GMS.formatMoney(net) + '</td></tr>';
        }).join('');
    html += '</tbody></table></div>';
    return html;
  }

  async function renderTopSelling() {
    const top = await GMS.topSellingProducts(10);
    const sales = await GMS.sales.all();
    let html = '<div class="table-wrap"><table><thead><tr><th>Rank</th><th>Product</th><th>Category</th><th class="cell-num">Units sold</th><th class="cell-num">Revenue</th></tr></thead><tbody>';
    html += top.length === 0
      ? '<tr class="empty-row"><td colspan="5">No sales recorded yet.</td></tr>'
      : top.map(function (r, i) {
          const revenue = sales.reduce(function (sum, s) {
            return sum + s.items.filter(function (it) { return String(it.productId) === String(r.product.id); }).reduce(function (s2, it) { return s2 + it.qty * it.price; }, 0);
          }, 0);
          return '<tr><td class="muted-cell">' + (i + 1) + '</td><td>' + r.product.name + '</td><td>' + GMS.categoryName(r.product.categoryId) + '</td><td class="cell-num">' + r.qty + '</td><td class="cell-num">' + GMS.formatMoney(revenue) + '</td></tr>';
        }).join('');
    html += '</tbody></table></div>';
    return html;
  }

  async function renderInventory() {
    const products = await GMS.products.all();
    const lowOrOut = products.filter(function (p) { return GMS.stockStatus(p) !== 'ok'; });
    const expiring = products.filter(function (p) { return GMS.isExpiringSoon(p, 30); });

    let html = '<h2 class="report-subtitle">Low stock &amp; out of stock</h2>';
    html += '<div class="table-wrap"><table><thead><tr><th>Product</th><th>Category</th><th class="cell-num">Stock</th><th>Reorder level</th><th>Status</th></tr></thead><tbody>';
    html += lowOrOut.length === 0
      ? '<tr class="empty-row"><td colspan="5">All products are adequately stocked.</td></tr>'
      : lowOrOut.map(function (p) {
          const status = GMS.stockStatus(p);
          const badge = status === 'out' ? '<span class="badge badge-out">Out of stock</span>' : '<span class="badge badge-low">Low stock</span>';
          return '<tr class="' + (status === 'out' ? 'row-danger' : 'row-warn') + '"><td>' + p.name + '</td><td>' + GMS.categoryName(p.categoryId) + '</td><td class="cell-num">' + p.stock + '</td><td class="cell-num">' + p.reorderLevel + '</td><td>' + badge + '</td></tr>';
        }).join('');
    html += '</tbody></table></div>';

    html += '<h2 class="report-subtitle report-subtitle--spaced">Expiring within 30 days</h2>';
    html += '<div class="table-wrap"><table><thead><tr><th>Product</th><th>Category</th><th class="cell-num">Stock</th><th>Expiry date</th><th>Days left</th></tr></thead><tbody>';
    html += expiring.length === 0
      ? '<tr class="empty-row"><td colspan="5">No products are expiring soon.</td></tr>'
      : expiring.sort(function (a, b) { return GMS.daysUntil(a.expiry) - GMS.daysUntil(b.expiry); }).map(function (p) {
          const days = GMS.daysUntil(p.expiry);
          return '<tr class="' + (days < 0 ? 'row-danger' : 'row-warn') + '"><td>' + p.name + '</td><td>' + GMS.categoryName(p.categoryId) + '</td><td class="cell-num">' + p.stock + '</td><td>' + GMS.formatDate(p.expiry) + '</td><td>' + (days < 0 ? 'Expired' : days + ' days') + '</td></tr>';
        }).join('');
    html += '</tbody></table></div>';
    return html;
  }

  async function renderSuppliers() {
    const suppliers = await GMS.suppliers.all();
    const purchases = await GMS.purchases.all();

    let html = '<div class="table-wrap"><table><thead><tr><th>Supplier</th><th>Phone</th><th class="cell-num">Orders</th><th class="cell-num">Total purchased</th><th>Last order</th></tr></thead><tbody>';
    html += suppliers.length === 0
      ? '<tr class="empty-row"><td colspan="5">No suppliers on file yet.</td></tr>'
      : suppliers.map(function (s) {
          const sp = purchases.filter(function (p) { return String(p.supplierId) === String(s.id); });
          const total = sp.reduce(function (sum, p) { return sum + GMS.docTotal(p); }, 0);
          const last = sp.map(function (p) { return p.date; }).sort().reverse()[0];
          return '<tr><td>' + s.name + '</td><td>' + (s.phone || '\u2014') + '</td><td class="cell-num">' + sp.length + '</td><td class="cell-num">' + GMS.formatMoney(total) + '</td><td>' + (last ? GMS.formatDate(last) : '\u2014') + '</td></tr>';
        }).join('');
    html += '</tbody></table></div>';
    return html;
  }

  async function render() {
    if (isCashierView && CASHIER_TABS.indexOf(activeTab) === -1) activeTab = 'sales';
    const period = document.getElementById('periodSelect').value;
    const isPeriod = TABS_WITH_PERIOD.includes(activeTab);
    const isInsights = activeTab === 'insights';
    const inYtd = isInsights && document.getElementById('insightRange').value === 'ytd';
    document.getElementById('dateFilterBar').style.display = (isPeriod || isInsights) ? 'flex' : 'none';
    document.getElementById('periodSelect').style.display = isPeriod ? '' : 'none';
    document.getElementById('insightRange').style.display = isInsights ? '' : 'none';
    document.getElementById('insightMonth').style.display = inYtd ? '' : 'none';

    const body = document.getElementById('reportBody');
    if (activeTab === 'sales') body.innerHTML = await renderSales(period);
    else if (activeTab === 'purchases') body.innerHTML = await renderPurchases(period);
    else if (activeTab === 'profit') body.innerHTML = await renderProfit(period);
    else if (activeTab === 'topselling') body.innerHTML = await renderTopSelling();
    else if (activeTab === 'inventory') body.innerHTML = await renderInventory();
    else if (activeTab === 'suppliers') body.innerHTML = await renderSuppliers();
    else if (activeTab === 'insights') body.innerHTML = await renderInsights();
  }

  document.getElementById('tabBar').addEventListener('click', function (e) {
    const btn = e.target.closest('button[data-tab]');
    if (!btn) return;
    activeTab = btn.getAttribute('data-tab');
    document.querySelectorAll('#tabBar button').forEach(function (b) {
      b.classList.toggle('btn-primary', b === btn);
      b.classList.toggle('btn-ghost', b !== btn);
    });
    render();
  });

  document.getElementById('periodSelect').addEventListener('change', render);
  document.getElementById('insightRange').addEventListener('change', render);
  document.getElementById('insightMonth').addEventListener('change', render);

  /* ================= Insights dashboard (all real DB data, inline SVG) ================= */

  var INSIGHT_COLORS = ['#2f9ebc', '#e8a13c', '#7fb069', '#e76f51', '#9d7bd8', '#e9c46a', '#4d96a9', '#ca6702'];
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function monLabel(key) {
    return MON[parseInt(String(key).slice(5, 7), 10) - 1] || key;
  }

  function compact(n) {
    n = Number(n) || 0;
    if (Math.abs(n) >= 1000) {
      var k = n / 1000;
      return (k >= 100 || k <= -100 ? Math.round(k) : Math.round(k * 10) / 10) + 'k';
    }
    return String(Math.round(n));
  }

  function lastNMonths(n) {
    var out = [];
    var now = new Date();
    for (var i = n - 1; i >= 0; i--) {
      var t = new Date(now.getFullYear(), now.getMonth() - i, 1);
      out.push(t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0'));
    }
    return out;
  }

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // Diverging month-on-month growth bars (teal up, red down).
  function growthSVG(months, growth) {
    var W = 660, H = 250, L = 46, R = 10, T = 22, B = 28;
    var plotW = W - L - R, plotH = H - T - B, mid = T + plotH / 2;
    var maxA = 1;
    growth.forEach(function (g) { if (g !== null) maxA = Math.max(maxA, Math.abs(g)); });
    var gw = plotW / Math.max(1, months.length);
    var bw = Math.max(4, Math.min(30, gw * 0.5));
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto" role="img">';
    s += '<line x1="' + L + '" y1="' + mid + '" x2="' + (W - R) + '" y2="' + mid + '" stroke="#b9b3a6" stroke-width="1"/>';
    s += '<text x="' + (L - 5) + '" y="' + (T + 4) + '" font-size="10" text-anchor="end" fill="#8a8478">+' + Math.round(maxA * 100) + '%</text>';
    s += '<text x="' + (L - 5) + '" y="' + (T + plotH) + '" font-size="10" text-anchor="end" fill="#8a8478">-' + Math.round(maxA * 100) + '%</text>';
    months.forEach(function (k, i) {
      var g = growth[i];
      var cx = L + gw * i + gw / 2;
      if (g === null) {
        s += '<text x="' + cx + '" y="' + (mid + 4) + '" font-size="10" text-anchor="middle" fill="#b9b3a6">—</text>';
      } else {
        var h = (plotH / 2) * Math.abs(g) / maxA;
        var y = g >= 0 ? mid - h : mid;
        var c = g >= 0 ? '#2f9ebc' : '#d9534f';
        s += '<rect x="' + (cx - bw / 2).toFixed(1) + '" y="' + y.toFixed(1) + '" width="' + bw + '" height="' + Math.max(1, h).toFixed(1) + '" fill="' + c + '"><title>' + (g >= 0 ? '+' : '') + (g * 100).toFixed(1) + '% vs previous month</title></rect>';
        var ly = g >= 0 ? y - 4 : y + h + 12;
        s += '<text x="' + cx + '" y="' + ly.toFixed(1) + '" font-size="9" text-anchor="middle" fill="#5a554c">' + (g >= 0 ? '+' : '') + Math.round(g * 100) + '%</text>';
      }
      s += '<text x="' + cx + '" y="' + (T + plotH + 18) + '" font-size="10" text-anchor="middle" fill="#5a554c">' + monLabel(k) + '</text>';
    });
    s += '</svg>';
    return s;
  }

  // Donut from value segments + HTML legend. Returns '' when total is 0.
  function donutSVG(segs, centerTop, centerBottom) {
    var total = segs.reduce(function (s, x) { return s + x.value; }, 0);
    if (total <= 0) return '<div class="text-muted">No data for this period.</div>';
    var R0 = 62, C = 2 * Math.PI * R0, off = 0;
    var s = '<svg viewBox="0 0 170 170" style="width:170px;height:auto" role="img">';
    segs.forEach(function (x) {
      var frac = x.value / total;
      s += '<circle cx="85" cy="85" r="' + R0 + '" fill="none" stroke="' + x.color + '" stroke-width="26"'
        + ' stroke-dasharray="' + (C * frac).toFixed(1) + ' ' + C.toFixed(1) + '"'
        + ' stroke-dashoffset="' + (-off).toFixed(1) + '" transform="rotate(-90 85 85)">'
        + '<title>' + escHtml(x.label) + ': ' + GMS.formatMoney(x.value) + '</title></circle>';
      off += C * frac;
    });
    s += '<text x="85" y="83" font-size="17" font-weight="bold" text-anchor="middle" fill="#333">' + centerTop + '</text>';
    s += '<text x="85" y="100" font-size="11" text-anchor="middle" fill="#8a8478">' + centerBottom + '</text>';
    s += '</svg>';
    s += '<div class="donut-legend">' + segs.map(function (x) {
      var pct = Math.round(x.value / total * 100);
      return '<div><i class="sw" style="background:' + x.color + '"></i>' + escHtml(x.label)
        + ' <b>' + GMS.formatMoney(x.value) + '</b> <span class="text-muted">(' + pct + '%)</span></div>';
    }).join('') + '</div>';
    return '<div class="donut-wrap">' + s + '</div>';
  }

  function hbarList(rows) {
    if (rows.length === 0) return '<div class="text-muted">No sales in this period.</div>';
    var max = Math.max.apply(null, rows.map(function (r) { return r.value; }).concat([1]));
    return '<div class="hbar-list">' + rows.map(function (r) {
      var pct = Math.max(2, Math.round(r.value / max * 100));
      return '<div class="hbar-row"><span class="hbar-label">' + escHtml(r.label) + '</span>'
        + '<span class="hbar-track"><span class="hbar-fill" style="width:' + pct + '%;background:' + r.color + '"></span></span>'
        + '<span class="hbar-val">' + GMS.formatMoney(r.value) + '</span></div>';
    }).join('') + '</div>';
  }

  // Targets API (monthly sales targets + gauge day-targets, owner-maintained).
  // Tables auto-create server-side; defaults kick in when nothing is set.
  function ensureInsightMonth() {
    var sel = document.getElementById('insightMonth');
    if (!sel || sel.options.length > 0) return;
    var now = new Date();
    for (var m = 1; m <= now.getMonth() + 1; m++) {
      var o = document.createElement('option');
      o.value = m; o.textContent = MON[m - 1];
      sel.appendChild(o);
    }
    sel.value = now.getMonth() + 1;
  }

  // Plain monthly sales bars (teal) with value labels — no goals, just
  // how much was sold each month.
  function monthBarSVG(months, actual) {
    var W = 660, H = 280, L = 46, R = 10, T = 20, B = 28;
    var plotW = W - L - R, plotH = H - T - B;
    var maxV = 1;
    months.forEach(function (k) { maxV = Math.max(maxV, actual[k] || 0); });
    var gw = plotW / Math.max(1, months.length);
    var bw = Math.max(5, Math.min(30, gw * 0.44));
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto" role="img">';
    for (var g = 0; g <= 4; g++) {
      var v = maxV * g / 4, y = T + plotH - plotH * g / 4;
      s += '<line x1="' + L + '" y1="' + y + '" x2="' + (W - R) + '" y2="' + y + '" stroke="#e3ddd2" stroke-width="1"/>';
      s += '<text x="' + (L - 5) + '" y="' + (y + 4) + '" font-size="10" text-anchor="end" fill="#8a8478">' + compact(v) + '</text>';
    }
    months.forEach(function (k, i) {
      var cx = L + gw * i + gw / 2;
      var a = actual[k] || 0;
      var yb = T + plotH;
      var ah = plotH * a / maxV;
      s += '<rect x="' + (cx - bw / 2).toFixed(1) + '" y="' + (yb - ah).toFixed(1) + '" width="' + bw + '" height="' + Math.max(0, ah).toFixed(1) + '" fill="#2f9ebc">'
        + '<title>' + monLabel(k) + ': ' + GMS.formatMoney(a) + '</title></rect>';
      if (a > 0) {
        s += '<text x="' + cx + '" y="' + (yb - ah - 5).toFixed(1) + '" font-size="9" text-anchor="middle" fill="#5a554c">' + compact(a) + '</text>';
      }
      s += '<text x="' + cx + '" y="' + (yb + 16) + '" font-size="10" text-anchor="middle" fill="#5a554c">' + monLabel(k) + '</text>';
    });
    s += '</svg>';
    return s;
  }

  // Two bars per row: actual (teal) vs last year (gray).
  function hbarDuo(rows) {
    if (rows.length === 0) return '<div class="text-muted">No sales in this period.</div>';
    var max = 1;
    rows.forEach(function (r) { max = Math.max(max, r.a, r.b); });
    return '<div class="hbar-list">' + rows.map(function (r) {
      var pa = Math.max(2, Math.round(r.a / max * 100));
      var pb = Math.max(2, Math.round(r.b / max * 100));
      return '<div class="hbar-duo-row"><span class="hbar-label">' + escHtml(r.label) + '</span>'
        + '<span class="hbar-duo-bars"><span class="hbar-track"><span class="hbar-fill" style="width:' + pa + '%;background:#2f9ebc"></span></span>'
        + '<span class="hbar-track"><span class="hbar-fill" style="width:' + pb + '%;background:#c7c2b8"></span></span></span>'
        + '<span class="hbar-val">' + GMS.formatMoney(r.a) + '<br><span class="text-muted">' + GMS.formatMoney(r.b) + '</span></span></div>';
    }).join('') + '</div>'
      + '<div class="chart-legend"><span><i class="sw" style="background:#2f9ebc"></i>Actual</span>'
      + '<span><i class="sw" style="background:#c7c2b8"></i>Last year</span></div>';
  }

  async function renderInsights() {
    var mode = document.getElementById('insightRange').value || 'last12';
    var sales = await GMS.sales.all();
    var products = await GMS.products.all();
    var expenses = await GMS.expenses.all();
    var customers = await GMS.customers.all();
    var custArea = {};
    customers.forEach(function (c) { custArea[String(c.id)] = c.area || ''; });

    ensureInsightMonth();
    var months, ytdEnd = 0;
    if (mode === 'last6') months = lastNMonths(6);
    else if (mode === 'ytd') {
      months = [];
      var yy = new Date().getFullYear();
      ytdEnd = parseInt(document.getElementById('insightMonth').value || (new Date().getMonth() + 1), 10);
      for (var m = 1; m <= ytdEnd; m++) months.push(yy + '-' + String(m).padStart(2, '0'));
    } else if (mode === 'all') {
      var set = {};
      sales.forEach(function (s) { if (s.date) set[String(s.date).slice(0, 7)] = 1; });
      expenses.forEach(function (e) { if (e.date) set[String(e.date).slice(0, 7)] = 1; });
      months = Object.keys(set).sort().slice(-24);
    } else months = lastNMonths(12);

    if (months.length === 0) {
      return '<div class="table-wrap"><table><tbody><tr class="empty-row"><td>No data yet — record some sales to see insights.</td></tr></tbody></table></div>';
    }

    var costOf = function (pid) {
      var p = products.find(function (x) { return String(x.id) === String(pid); });
      return p ? (Number(p.purchasePrice) || 0) : 0;
    };
    var rev = {}, cogs = {}, exp = {}, bills = {}, collected = {};
    months.forEach(function (k) { rev[k] = 0; cogs[k] = 0; exp[k] = 0; bills[k] = 0; collected[k] = 0; });
    sales.forEach(function (s) {
      var k = String(s.date || '').slice(0, 7);
      if (!(k in rev)) return;
      var t = GMS.docTotal(s);
      rev[k] += t;
      bills[k] += 1;
      collected[k] += Number(s.cashReceived) || 0;
      cogs[k] += (s.items || []).reduce(function (sum, it) {
        var c = (it.cost !== undefined && it.cost !== null) ? Number(it.cost) : costOf(it.productId);
        return sum + Number(it.qty) * c;
      }, 0);
    });
    expenses.forEach(function (e) {
      var k = String(e.date || '').slice(0, 7);
      if (k in exp) exp[k] += Number(e.amount) || 0;
    });

    var tRev = 0, tCogs = 0, tExp = 0, tBills = 0, tColl = 0, lyRev = 0;
    months.forEach(function (k) {
      tRev += rev[k]; tCogs += cogs[k]; tExp += exp[k]; tBills += bills[k]; tColl += collected[k];
      var ly = (parseInt(k.slice(0, 4), 10) - 1) + k.slice(4);
      sales.forEach(function (s) {
        if (String(s.date || '').slice(0, 7) === ly) lyRev += GMS.docTotal(s);
      });
    });
    var gross = tRev - tCogs, net = gross - tExp;
    var margin = tRev > 0 ? gross / tRev * 100 : 0;
    var collPct = tRev > 0 ? tColl / tRev * 100 : 100;
    var due = Math.max(0, tRev - tColl);
    var yoy = lyRev > 0 ? (tRev - lyRev) / lyRev * 100 : null;

    var lySet = {};
    months.forEach(function (k) { lySet[(parseInt(k.slice(0, 4), 10) - 1) + k.slice(4)] = 1; });
    var growth = months.map(function (k, i) {
      if (i === 0) return null;
      var prev = rev[months[i - 1]] || 0;
      if (prev <= 0) return rev[k] > 0 ? 1 : null;
      return (rev[k] - prev) / prev;
    });

    var catTotals = {}, catLY = {}, lyCogs = 0, walkRev = 0, regRev = 0, areaRev = {}, areaLY = {};
    sales.forEach(function (s) {
      var k = String(s.date || '').slice(0, 7);
      var t = GMS.docTotal(s);
      var ar = s.customerId ? (custArea[String(s.customerId)] || 'Unknown') : 'Walk-in';
      if (months.indexOf(k) !== -1) {
        areaRev[ar] = (areaRev[ar] || 0) + t;
        if (s.customerId) regRev += t; else walkRev += t;
      }
      if (!!lySet[k]) areaLY[ar] = (areaLY[ar] || 0) + t;
      (s.items || []).forEach(function (it) {
        var p = products.find(function (x) { return String(x.id) === String(it.productId); });
        var line = Number(it.qty) * Number(it.price);
        if (months.indexOf(k) !== -1) {
          var c = p ? GMS.categoryName(p.categoryId) : '—';
          catTotals[c] = (catTotals[c] || 0) + line;
        }
        if (!!lySet[k]) {
          var c2 = p ? GMS.categoryName(p.categoryId) : '—';
          catLY[c2] = (catLY[c2] || 0) + line;
          var cc = (it.cost !== undefined && it.cost !== null) ? Number(it.cost) : costOf(it.productId);
          lyCogs += Number(it.qty) * cc;
        }
      });
    });
    var catSegs = Object.keys(catTotals).map(function (c, i) {
      return { label: c, value: catTotals[c], color: INSIGHT_COLORS[i % INSIGHT_COLORS.length] };
    }).sort(function (a, b) { return b.value - a.value; });

    var prodTotals = {};
    sales.forEach(function (s) {
      var k = String(s.date || '').slice(0, 7);
      if (months.indexOf(k) === -1) return;
      (s.items || []).forEach(function (it) {
        var p = products.find(function (x) { return String(x.id) === String(it.productId); });
        var n = p ? p.name : ('#' + it.productId);
        prodTotals[n] = (prodTotals[n] || 0) + Number(it.qty) * Number(it.price);
      });
    });
    var topRows = Object.keys(prodTotals).map(function (n, i) {
      return { label: n, value: prodTotals[n], color: INSIGHT_COLORS[i % INSIGHT_COLORS.length] };
    }).sort(function (a, b) { return b.value - a.value; }).slice(0, 5);

    var kpi = function (label, value, accent, neg) {
      return '<div class="stat accent-' + accent + '"><div class="label">' + label + '</div>'
        + '<div class="value' + (neg ? ' neg' : '') + '">' + value + '</div></div>';
    };
    var rangeName = { last6: 'Last 6 months', last12: 'Last 12 months', ytd: 'Year to date', all: 'All time' }[mode] || mode;
    if (mode === 'ytd') rangeName += ' through ' + MON[ytdEnd - 1];
    var html = '<div class="insight-head"><span>YTD Dashboard</span><span class="insight-range">' + escHtml(rangeName) + '</span></div>'
      + '<div class="stat-row stat-row--spaced">'
      + kpi('Sales', GMS.formatMoney(tRev)
          + (yoy === null ? '' : ' <span class="kpi-sub ' + (yoy >= 0 ? 'profit-pos' : 'profit-neg') + '">YoY ' + (yoy >= 0 ? '+' : '') + yoy.toFixed(1) + '%</span>'), 'green')
      + kpi('Sales last year', GMS.formatMoney(lyRev), 'green')
      + kpi('Gross profit', GMS.formatMoney(gross), gross < 0 ? 'red' : 'green', gross < 0)
      + kpi('Gross margin', margin.toFixed(1) + '%', 'turmeric')
      + '</div>';
    html += '<div class="stat-row stat-row--spaced">'
      + kpi('Bills', tBills, 'green')
      + kpi('Avg bill', GMS.formatMoney(tBills ? tRev / tBills : 0), 'green')
      + kpi('Cash collected', collPct.toFixed(1) + '%', collPct < 90 ? 'red' : 'green', collPct < 90)
      + kpi('Due outstanding', GMS.formatMoney(due), due > 0 ? 'red' : 'green', due > 0)
      + '</div>';
    html += '<div class="text-muted report-note">'
      + 'Gross = revenue &minus; cost of goods actually sold. Net profit is on the Profit tab.'
      + '</div>';

    var catDuo = catSegs.map(function (x) {
      return { label: x.label, a: x.value, b: catLY[x.label] || 0 };
    });
    var areaDuo = Object.keys(areaRev).map(function (a) {
      return { label: a, a: areaRev[a], b: areaLY[a] || 0 };
    }).sort(function (x, y) { return y.a - x.a; });
    html += '<div class="insight-grid">'
      + '<div class="insight-card insight-span"><h3 class="report-subtitle">Monthly sales</h3>' + monthBarSVG(months, rev) + '</div>'
      + '<div class="insight-card insight-span"><h3 class="report-subtitle">Sales growth (month-on-month)</h3>' + growthSVG(months, growth) + '</div>'
      + '<div class="insight-card"><h3 class="report-subtitle">Sales by product (top 5)</h3>' + hbarList(topRows) + '</div>'
      + '<div class="insight-card"><h3 class="report-subtitle">Sales by category (vs last year)</h3>' + hbarDuo(catDuo) + '</div>'
      + '<div class="insight-card"><h3 class="report-subtitle">Sales by customer type</h3>' + donutSVG([
          { label: 'Walk-in', value: walkRev, color: '#2f9ebc' },
          { label: 'Registered', value: regRev, color: '#e8a13c' }
        ], tRev > 0 ? compact(tRev) : '—', 'revenue') + '</div>'
      + '<div class="insight-card"><h3 class="report-subtitle">Sales by area (vs last year)</h3>' + hbarDuo(areaDuo) + '</div>'
      + '<div class="insight-card"><h3 class="report-subtitle">Cash collected vs due</h3>' + donutSVG([
          { label: 'Collected', value: tColl, color: '#2f9ebc' },
          { label: 'Due', value: due, color: '#d9534f' }
        ], collPct.toFixed(0) + '%', 'collected') + '</div>'
      + '</div>';
    return html;
  }

  document.getElementById('periodSelect').addEventListener('change', render);

  render();
});
