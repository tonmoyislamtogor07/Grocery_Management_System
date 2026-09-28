document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  let activeTab = 'sales';
  const TABS_WITH_PERIOD = ['sales', 'purchases', 'profit'];

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
    const period = document.getElementById('periodSelect').value;
    document.getElementById('dateFilterBar').style.display = TABS_WITH_PERIOD.includes(activeTab) ? 'flex' : 'none';

    const body = document.getElementById('reportBody');
    if (activeTab === 'sales') body.innerHTML = await renderSales(period);
    else if (activeTab === 'purchases') body.innerHTML = await renderPurchases(period);
    else if (activeTab === 'profit') body.innerHTML = await renderProfit(period);
    else if (activeTab === 'topselling') body.innerHTML = await renderTopSelling();
    else if (activeTab === 'inventory') body.innerHTML = await renderInventory();
    else if (activeTab === 'suppliers') body.innerHTML = await renderSuppliers();
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

  render();
});
