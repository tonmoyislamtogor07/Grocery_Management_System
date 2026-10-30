document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const today = GMS.todayISO();
  const allSales = await GMS.sales.all();
  const sales = allSales.filter(function (s) { return s.date === today; });

  const bills = sales.length;
  const totalSales = sales.reduce(function (sum, sale) { return sum + GMS.docTotal(sale); }, 0);
  const cashReceived = allSales.reduce(function (sum, sale) {
    if (Array.isArray(sale.payments)) {
      return sum + sale.payments.filter(function (payment) { return payment.date === today; })
        .reduce(function (paid, payment) { return paid + (Number(payment.amount) || 0); }, 0);
    }
    return sum + (sale.date === today ? GMS.docPaid(sale) : 0);
  }, 0);
  const due = sales.reduce(function (sum, sale) { return sum + GMS.docDue(sale); }, 0);

  const statCards = [
    { label: 'Total bills (today)', value: bills, accent: 'green' },
    { label: 'Total sales (today)', value: GMS.formatMoney(totalSales), accent: 'green' },
    { label: 'Cash received (today)', value: GMS.formatMoney(cashReceived), accent: 'turmeric' },
    { label: 'Due (today)', value: GMS.formatMoney(due), accent: due > 0 ? 'red' : 'green', neg: due > 0 },
  ];

  document.getElementById('statRow').innerHTML = statCards.map(function (c) {
    return '<div class="stat accent-' + c.accent + '"><div class="label">' + c.label + '</div>' +
      '<div class="value' + (c.neg ? ' neg' : '') + '">' + c.value + '</div></div>';
  }).join('');

  const body = document.getElementById('todayBody');
  if (sales.length === 0) {
    body.innerHTML = '<tr class="empty-row"><td colspan="8">No sales today yet — start with a New Sale.</td></tr>';
    return;
  }

  const rows = sales.slice().sort(function (a, b) {
    return (b.time || '').localeCompare(a.time || '') || String(b.id).localeCompare(String(a.id));
  });

  body.innerHTML = rows.map(function (s) {
    const total = GMS.docTotal(s);
    const cash = GMS.docPaid(s);
    const d = GMS.docDue(s);
    const dueCell = d > 0
      ? '<span class="badge badge-out">' + GMS.formatMoney(d) + '</span>'
      : '<span class="badge badge-ok">Paid</span>';
    return '<tr>' +
      '<td class="muted-cell">' + s.id + '</td>' +
      '<td>' + GMS.customerName(s.customerId) + '</td>' +
      '<td>' + (s.time || '\u2014') + '</td>' +
      '<td class="cell-num">' + s.items.length + '</td>' +
      '<td class="cell-num">' + GMS.formatMoney(total) + '</td>' +
      '<td class="cell-num">' + GMS.formatMoney(cash) + '</td>' +
      '<td>' + dueCell + '</td>' +
      '<td class="row-actions">' +
        (d > 0 ? '<a class="btn btn-primary btn-sm" href="sales.html">Collect</a>' : '') +
        '<button class="btn btn-ghost btn-sm" onclick="viewBill(\'' + s.id + '\')">View</button></td>' +
      '</tr>';
  }).join('');

  window.viewBill = async function (id) {
    const all = await GMS.sales.all();
    const s = all.find(function (v) { return String(v.id) === String(id); });
    if (!s) return;
    const prodList = await GMS.products.all();
    const lines = s.items.map(function (it) {
      const prod = prodList.find(function (p) { return String(p.id) === String(it.productId); });
      return '- ' + (prod ? prod.name : it.productId) + '  x' + it.qty + '  @ ' + GMS.formatMoney(it.price);
    }).join('\n');
    const total = GMS.docTotal(s);
    const d = GMS.docDue(s);
    alert('Receipt ' + s.id + '\nCustomer: ' + GMS.customerName(s.customerId) + '\nDate: ' + GMS.formatDate(s.date) + '\n\n' + lines + '\n\nDiscount: ' + GMS.formatMoney(GMS.docDiscount(s)) + '\nTotal: ' + GMS.formatMoney(total) + '\nCash received: ' + GMS.formatMoney(GMS.docPaid(s)) + '\nDue: ' + GMS.formatMoney(d));
  };
});
