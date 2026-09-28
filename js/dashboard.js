document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const stats = await GMS.dashboardStats();

  const statCards = [
    { label: "Today's Revenue", value: GMS.formatMoney(stats.todayRevenue), accent: 'turmeric' },
    { label: 'Total Sales', value: GMS.formatMoney(stats.totalSales), accent: 'green' },
    { label: 'Total Purchases', value: GMS.formatMoney(stats.totalPurchases), accent: 'green' },
    { label: 'Gross Profit (sales − cost of sold goods)', value: GMS.formatMoney(stats.grossProfit), accent: stats.grossProfit < 0 ? 'red' : 'green', neg: stats.grossProfit < 0 },
    { label: 'Net Profit (gross − expenses)', value: GMS.formatMoney(stats.netProfit), accent: stats.netProfit < 0 ? 'red' : 'green', neg: stats.netProfit < 0 },
    { label: 'Products in Catalog', value: stats.productCount, accent: 'green' },
    { label: 'Low / Out of Stock', value: stats.lowStockCount, accent: stats.lowStockCount > 0 ? 'red' : 'green' }
  ];

  document.getElementById('statRow').innerHTML = statCards.map(function (c) {
    return '<div class="stat accent-' + c.accent + '"><div class="label">' + c.label + '</div>' +
      '<div class="value' + (c.neg ? ' neg' : '') + '">' + c.value + '</div></div>';
  }).join('');

  if (stats.lowStockCount > 0) {
    document.getElementById('lowStockNotice').innerHTML =
      '<div class="notice notice-red">' + stats.lowStockCount + ' product(s) are low or out of stock. Check the watch list below or head to Products to reorder.</div>';
  }

  // Low stock / expiry watch (live DB rows)
  const products = (await GMS.products.all()).slice();
  const watch = products
    .filter(function (p) { return GMS.stockStatus(p) !== 'ok' || GMS.isExpiringSoon(p, 30); })
    .sort(function (a, b) { return a.stock - b.stock; });

  const watchBody = document.getElementById('watchBody');
  if (watch.length === 0) {
    watchBody.innerHTML = '<tr class="empty-row"><td colspan="5">Nothing needs attention right now — stock and expiry dates look healthy.</td></tr>';
  } else {
    watchBody.innerHTML = watch.map(function (p) {
      const status = GMS.stockStatus(p);
      const badge = status === 'out' ? '<span class="badge badge-out">Out of stock</span>'
        : status === 'low' ? '<span class="badge badge-low">Low stock</span>'
        : '<span class="badge badge-ok">OK</span>';
      const days = GMS.daysUntil(p.expiry);
      const expiryNote = days !== null && days <= 30 ? ' <span class="text-muted">(' + (days < 0 ? 'expired' : days + 'd left') + ')</span>' : '';
      return '<tr class="' + (status === 'out' ? 'row-danger' : status === 'low' ? 'row-warn' : '') + '">' +
        '<td>' + p.name + '</td><td>' + GMS.categoryName(p.categoryId) + '</td>' +
        '<td class="cell-num">' + p.stock + '</td><td>' + badge + '</td>' +
        '<td>' + GMS.formatDate(p.expiry) + expiryNote + '</td></tr>';
    }).join('');
  }

  // Top selling products
  const top = await GMS.topSellingProducts(5);
  const topBody = document.getElementById('topSellingBody');
  topBody.innerHTML = top.length === 0
    ? '<tr class="empty-row"><td colspan="2">No sales recorded yet.</td></tr>'
    : top.map(function (r) { return '<tr><td>' + r.product.name + '</td><td class="cell-num">' + r.qty + '</td></tr>'; }).join('');

  // Recent sales
  const sales = await GMS.sales.all();
  const recent = sales.slice().sort(function (a, b) { return b.date.localeCompare(a.date); }).slice(0, 6);
  const recentBody = document.getElementById('recentSalesBody');
  recentBody.innerHTML = recent.length === 0
    ? '<tr class="empty-row"><td colspan="4">No sales recorded yet.</td></tr>'
    : recent.map(function (s) {
        return '<tr><td>' + s.id + '</td><td>' + GMS.customerName(s.customerId) + '</td><td>' + GMS.formatDate(s.date) + '</td><td class="cell-num">' + GMS.formatMoney(GMS.docTotal(s)) + '</td></tr>';
      }).join('');
});
