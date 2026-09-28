document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const form = document.getElementById('saleForm');
  const lineItemsBody = document.getElementById('lineItemsBody');
  let searchTerm = '';
  let lineCounter = 0;

  async function customerOptions() {
    const all = await GMS.customers.all();
    return all.map(function (c) { return '<option value="' + c.id + '">' + c.name + '</option>'; }).join('');
  }

  async function productOptions(selectedId) {
    const all = await GMS.products.all();
    return all.map(function (p) {
      const disabled = p.stock <= 0 && String(p.id) !== String(selectedId) ? ' disabled' : '';
      return '<option value="' + p.id + '" data-price="' + p.price + '" data-stock="' + p.stock + '"' + (String(p.id) === String(selectedId) ? ' selected' : '') + disabled + '>' + p.name + (p.stock <= 0 ? ' (out of stock)' : '') + '</option>';
    }).join('');
  }

  async function productById(id) {
    const all = await GMS.products.all();
    return all.find(function (p) { return String(p.id) === String(id); }) || null;
  }

  async function addLineRow() {
    if ((await GMS.products.all()).length === 0) { GMSApp.showToast('Add products before creating a sale.', true); return; }
    lineCounter++;
    const rowId = 'sline' + lineCounter;
    const row = document.createElement('tr');
    row.id = rowId;
    row.innerHTML =
      '<td><select class="line-product">' + await productOptions() + '</select></td>' +
      '<td><input type="number" class="line-qty" min="1" step="1" value="1"></td>' +
      '<td><input type="number" class="line-price" min="0" step="0.01"></td>' +
      '<td class="cell-num line-total">৳0.00</td>' +
      '<td><button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById(\'' + rowId + '\').remove(); recalc();">&times;</button></td>';
    lineItemsBody.appendChild(row);

    const select = row.querySelector('.line-product');
    const priceInput = row.querySelector('.line-price');
    const opt = select.options[select.selectedIndex];
    priceInput.value = opt ? (opt.dataset.price || 0) : 0;

    select.addEventListener('change', function () {
      const o = select.options[select.selectedIndex];
      priceInput.value = o ? (o.dataset.price || 0) : 0;
      recalc();
    });
    row.querySelector('.line-qty').addEventListener('input', recalc);
    priceInput.addEventListener('input', recalc);
    recalc();
  }

  function resetReceipt() {
    document.getElementById('rvReceiptId').textContent = '\u2014';
    document.getElementById('rvDate').textContent = GMS.formatDate(document.getElementById('saleDate').value || GMS.todayISO());
    document.getElementById('rvLines').innerHTML = '<div class="text-muted receipt-empty">No sale yet — totals are ৳0.00 by default.</div>';
    document.getElementById('rvTotal').textContent = GMS.formatMoney(0);
    document.getElementById('rvCash').textContent = GMS.formatMoney(0);
    document.getElementById('rvChange').textContent = GMS.formatMoney(0);
    document.getElementById('rvDue').textContent = GMS.formatMoney(0);
  }

  async function renderCompletedReceipt(sale, total) {
    const prodList = await GMS.products.all();
    const rvLines = document.getElementById('rvLines');
    rvLines.innerHTML = sale.items.map(function (it) {
      const prod = prodList.find(function (p) { return String(p.id) === String(it.productId); });
      const name = prod ? prod.name : it.productId;
      return '<div class="receipt-line"><span>' + name + ' &times;' + it.qty + '</span><span>' + GMS.formatMoney(it.qty * it.price) + '</span></div>';
    }).join('');
    document.getElementById('rvReceiptId').textContent = sale.id;
    document.getElementById('rvDate').textContent = GMS.formatDate(sale.date);
    document.getElementById('rvTotal').textContent = GMS.formatMoney(total);
    document.getElementById('rvCash').textContent = GMS.formatMoney(sale.cashReceived);
    document.getElementById('rvChange').textContent = GMS.formatMoney(Math.max(0, (Number(sale.cashReceived) || 0) - total));
    document.getElementById('rvDue').textContent = GMS.formatMoney(Math.max(0, total - (Number(sale.cashReceived) || 0)));
  }

  async function currentLines() {
    const prodList = await GMS.products.all();
    const lines = [];
    lineItemsBody.querySelectorAll('tr').forEach(function (row) {
      const select = row.querySelector('.line-product');
      const productId = Number(select.value);
      const qty = parseFloat(row.querySelector('.line-qty').value) || 0;
      const price = parseFloat(row.querySelector('.line-price').value) || 0;
      const product = prodList.find(function (p) { return Number(p.id) === productId; }) || null;
      lines.push({ productId: productId, qty: qty, price: price, product: product });
    });
    return lines;
  }

  async function recalc() {
    let total = 0;
    lineItemsBody.querySelectorAll('tr').forEach(function (row) {
      const qty = parseFloat(row.querySelector('.line-qty').value) || 0;
      const price = parseFloat(row.querySelector('.line-price').value) || 0;
      const lineTotal = qty * price;
      row.querySelector('.line-total').textContent = GMS.formatMoney(lineTotal);
      total += lineTotal;
    });
    updateReceipt(total);
  }
  window.recalc = recalc;

  async function updateReceipt(total) {
    const lines = (await currentLines()).filter(function (l) { return l.product; });
    const rvLines = document.getElementById('rvLines');
    if (lines.length === 0) {
      rvLines.innerHTML = '<div class="text-muted receipt-empty">No sale yet — totals are ৳0.00 by default.</div>';
    } else {
      rvLines.innerHTML = lines.map(function (l) {
        return '<div class="receipt-line"><span>' + l.product.name + ' &times;' + l.qty + '</span><span>' + GMS.formatMoney(l.qty * l.price) + '</span></div>';
      }).join('');
    }
    document.getElementById('rvTotal').textContent = GMS.formatMoney(total);
    const cash = parseFloat(document.getElementById('cashReceived').value) || 0;
    document.getElementById('rvCash').textContent = GMS.formatMoney(cash);
    document.getElementById('rvChange').textContent = GMS.formatMoney(Math.max(0, cash - total));
    document.getElementById('rvDue').textContent = GMS.formatMoney(Math.max(0, total - cash));
    document.getElementById('rvDate').textContent = GMS.formatDate(document.getElementById('saleDate').value || GMS.todayISO());
  }

  document.getElementById('cashReceived').addEventListener('input', function () { recalc(); });
  document.getElementById('saleDate').addEventListener('change', function () { recalc(); });
  document.getElementById('addLineBtn').addEventListener('click', addLineRow);
  document.getElementById('searchInput').addEventListener('input', function (e) { searchTerm = e.target.value; renderHistory(); });

  document.getElementById('resetBtn').addEventListener('click', function () {
    form.reset();
    lineItemsBody.innerHTML = '';
    document.getElementById('saleDate').value = GMS.todayISO();
    resetReceipt();
  });

  document.getElementById('printReceiptBtn').addEventListener('click', function () {
    window.print();
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const customerRaw = document.getElementById('saleCustomer').value;
    const customerId = customerRaw ? Number(customerRaw) : null;
    const date = document.getElementById('saleDate').value || GMS.todayISO();
    const cashRaw = document.getElementById('cashReceived').value;
    const cashReceived = parseFloat(cashRaw) || 0;

    let dateErr = GMS.validate.date(date, 'Sale date') || GMS.validate.money(cashRaw === '' ? 0 : cashRaw, 'Cash received');
    if (dateErr) { GMSApp.showToast(dateErr, true); return; }

    const lines = await currentLines();
    let lineErr = null;
    lines.forEach(function (l) {
      if (lineErr) return;
      if (!l.productId || !l.product) lineErr = 'Every row needs a valid product.';
      else if (!Number.isInteger(l.qty) || l.qty <= 0) lineErr = 'Quantity must be a whole number of at least 1.';
      else if (!(l.price >= 0) || !isFinite(l.price)) lineErr = 'Unit price cannot be negative.';
      else if (l.qty > l.product.stock) lineErr = 'Not enough stock for ' + l.product.name + ' (have ' + l.product.stock + ').';
    });

    if (lineErr || lines.length === 0) {
      GMSApp.showToast(lineErr || 'Add at least one item.', true);
      return;
    }

    const items = lines.map(function (l) { return { productId: l.productId, qty: l.qty, price: l.price }; });
    const total = items.reduce(function (s, it) { return s + it.qty * it.price; }, 0);

    // Partial payment is allowed — the unpaid part stays as due.
    const due = Math.max(0, total - cashReceived);

    try {
      // Saved to sales + sale_details; stock auto-decreases in PHP.
      const sale = await GMS.sales.add({ customerId: customerId, date: date, items: items, cashReceived: cashReceived });
      renderCompletedReceipt(sale, total);
      GMSApp.showToast(due > 0
        ? 'Sale saved — receipt #' + sale.id + ' with due ' + GMS.formatMoney(due) + '.'
        : 'Sale completed in database — receipt #' + sale.id + '. Stock updated.');
      renderHistory();

      setTimeout(function () { window.print(); }, 100);

      setTimeout(async function () {
        form.reset();
        lineItemsBody.innerHTML = '';
        document.getElementById('saleDate').value = GMS.todayISO();
        document.getElementById('saleCustomer').innerHTML = '<option value="">Walk-in customer</option>' + await customerOptions();
        resetReceipt();
      }, 600);
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

  async function renderHistory() {
    const term = searchTerm.toLowerCase();
    const all = await GMS.sales.all();
    const rows = all
      .filter(function (s) { return GMS.customerName(s.customerId).toLowerCase().includes(term) || String(s.id).toLowerCase().includes(term); })
      .sort(function (a, b) { return b.date.localeCompare(a.date) || String(b.id).localeCompare(String(a.id)); });

    const body = document.getElementById('historyBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="7">No sales recorded yet.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (s) {
      const total = GMS.docTotal(s);
      const due = Math.max(0, total - (Number(s.cashReceived) || 0));
      const dueCell = due > 0
        ? '<span class="badge badge-out">' + GMS.formatMoney(due) + '</span>'
        : '<span class="badge badge-ok">Paid</span>';
      return '<tr>' +
        '<td class="muted-cell">' + s.id + '</td>' +
        '<td>' + GMS.customerName(s.customerId) + '</td>' +
        '<td>' + GMS.formatDate(s.date) + '</td>' +
        '<td class="cell-num">' + s.items.length + '</td>' +
        '<td class="cell-num">' + GMS.formatMoney(total) + '</td>' +
        '<td>' + dueCell + '</td>' +
        '<td class="row-actions"><button class="btn btn-ghost btn-sm" onclick="viewSale(\'' + s.id + '\')">View</button></td>' +
        '</tr>';
    }).join('');
  }

  window.viewSale = async function (id) {
    const all = await GMS.sales.all();
    const s = all.find(function (v) { return String(v.id) === String(id); });
    if (!s) return;
    const prodList = await GMS.products.all();
    const lines = s.items.map(function (it) {
      const prod = prodList.find(function (p) { return String(p.id) === String(it.productId); });
      return '- ' + (prod ? prod.name : it.productId) + '  x' + it.qty + '  @ ' + GMS.formatMoney(it.price);
    }).join('\n');
    const total = GMS.docTotal(s);
    const due = Math.max(0, total - (Number(s.cashReceived) || 0));
    alert('Receipt ' + s.id + '\nCustomer: ' + GMS.customerName(s.customerId) + '\nDate: ' + GMS.formatDate(s.date) + '\n\n' + lines + '\n\nTotal: ' + GMS.formatMoney(total) + '\nCash received: ' + GMS.formatMoney(s.cashReceived) + '\nDue: ' + GMS.formatMoney(due));
  };

  // init — new sale starts empty, right-side receipt stays ৳0.00 by default.
  document.getElementById('saleCustomer').innerHTML = '<option value="">Walk-in customer</option>' + await customerOptions();
  document.getElementById('saleDate').value = GMS.todayISO();
  lineItemsBody.innerHTML = '';
  resetReceipt();
  renderHistory();
});
