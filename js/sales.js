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

  function escAttr(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Searchable product picker (native datalist): type a few letters to
  // filter, even with 1000+ products. Names are UNIQUE, so name => product.
  async function productDatalist() {
    let dl = document.getElementById('saleProductList');
    if (!dl) {
      dl = document.createElement('datalist');
      dl.id = 'saleProductList';
      document.body.appendChild(dl);
    }
    const all = await GMS.products.all();
    dl.innerHTML = all.map(function (p) {
      return '<option value="' + escAttr(p.name) + '"></option>';
    }).join('');
    return all;
  }

  function findProductByName(all, name) {
    const s = String(name || '').trim().toLowerCase();
    if (!s) return null;
    return all.find(function (p) { return String(p.name).toLowerCase() === s; }) || null;
  }

  async function productById(id) {
    const all = await GMS.products.all();
    return all.find(function (p) { return String(p.id) === String(id); }) || null;
  }

  async function addLineRow() {
    if ((await GMS.products.all()).length === 0) { GMSApp.showToast('Add products before creating a sale.', true); return; }
    await productDatalist();
    lineCounter++;
    const rowId = 'sline' + lineCounter;
    const row = document.createElement('tr');
    row.id = rowId;
    row.innerHTML =
      '<td><input class="line-product" list="saleProductList" placeholder="Type to search..." autocomplete="off">' +
      '<div class="line-hint text-muted"></div></td>' +
      '<td><input type="number" class="line-qty" min="1" step="1" value="1"></td>' +
      '<td><input type="number" class="line-price" min="0" step="0.01"></td>' +
      '<td class="cell-num line-total">৳0.00</td>' +
      '<td><button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById(\'' + rowId + '\').remove(); recalc();">&times;</button></td>';
    lineItemsBody.appendChild(row);

    const input = row.querySelector('.line-product');
    const hint = row.querySelector('.line-hint');
    const priceInput = row.querySelector('.line-price');
    input.addEventListener('input', async function () {
      const list = await GMS.products.all();
      const p = findProductByName(list, input.value);
      if (p) {
        row.dataset.productId = p.id;
        hint.textContent = GMS.formatMoney(p.price) + ' | stock ' + p.stock + (p.stock <= 0 ? ' (out of stock)' : '');
        priceInput.value = p.price;
      } else {
        delete row.dataset.productId;
        hint.textContent = input.value ? 'Pick a product from the list.' : '';
      }
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
      const productId = Number(row.dataset.productId || 0);
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
      const collectBtn = due > 0 ? '<button class="btn btn-primary btn-sm" onclick="openCollect(\'' + s.id + '\')">Collect</button>' : '';
      return '<tr>' +
        '<td class="muted-cell">' + s.id + '</td>' +
        '<td>' + GMS.customerName(s.customerId) + '</td>' +
        '<td>' + GMS.formatDate(s.date) + '</td>' +
        '<td class="cell-num">' + s.items.length + '</td>' +
        '<td class="cell-num">' + GMS.formatMoney(total) + '</td>' +
        '<td>' + dueCell + '</td>' +
        '<td class="row-actions">' + collectBtn + '<button class="btn btn-ghost btn-sm" onclick="viewSale(\'' + s.id + '\')">View</button></td>' +
        '</tr>';
    }).join('');
  }

  // ---- Due collection ----
  const collectBackdrop = document.getElementById('collectBackdrop');
  const collectForm = document.getElementById('collectForm');

  function closeCollect() { collectBackdrop.classList.remove('open'); collectForm.reset(); document.getElementById('collectSaleId').value = ''; }
  document.getElementById('collectClose').addEventListener('click', closeCollect);
  document.getElementById('collectCancel').addEventListener('click', closeCollect);
  collectBackdrop.addEventListener('click', function (e) { if (e.target === collectBackdrop) closeCollect(); });

  window.openCollect = async function (id) {
    const all = await GMS.sales.all();
    const s = all.find(function (v) { return String(v.id) === String(id); });
    if (!s) return;
    const due = Math.max(0, GMS.docTotal(s) - (Number(s.cashReceived) || 0));
    if (due <= 0) { GMSApp.showToast('This bill has no due left.', true); return; }
    document.getElementById('collectSaleId').value = s.id;
    document.getElementById('collectTitle').textContent = 'Collect due — Receipt #' + s.id + ' (' + GMS.customerName(s.customerId) + ')';
    document.getElementById('collectDueText').textContent = GMS.formatMoney(due);
    document.getElementById('collectAmount').value = due.toFixed(2);
    document.getElementById('collectAmount').max = due.toFixed(2);
    document.getElementById('collectDate').value = GMS.todayISO();
    collectBackdrop.classList.add('open');
  };

  async function collectPayments(saleId) {
    const res = await fetch('api/sale_payments.php?sale_id=' + encodeURIComponent(saleId), { credentials: 'same-origin' });
    const body = await res.json().catch(function () { return null; });
    if (!res.ok || !body || body.ok === false) throw new Error((body && body.error) || 'Could not load payments.');
    return body.data || [];
  }

  collectForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const saleId = Number(document.getElementById('collectSaleId').value);
    const amountRaw = document.getElementById('collectAmount').value;
    const date = document.getElementById('collectDate').value || GMS.todayISO();
    const err = GMS.validate.money(amountRaw, 'Amount', true) || GMS.validate.date(date, 'Payment date');
    if (err) { GMSApp.showToast(err, true); return; }
    try {
      const res = await fetch('api/sale_payments.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ saleId: saleId, amount: parseFloat(amountRaw), date: date }),
      });
      const body = await res.json().catch(function () { return null; });
      if (!res.ok || !body || body.ok === false) throw new Error((body && body.error) || 'Could not save payment.');
      await GMS.refresh('sales');
      closeCollect();
      renderHistory();
      GMSApp.showToast(body.data.due > 0
        ? 'Payment saved. Remaining due ' + GMS.formatMoney(body.data.due) + '.'
        : 'Fully paid. Receipt #' + saleId + ' has no due left.');
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

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
    let payText = '';
    try {
      const pays = await collectPayments(s.id);
      if (pays.length > 0) payText = '\nPayments:\n' + pays.map(function (p) { return '- ' + GMS.formatMoney(p.amount) + ' on ' + GMS.formatDate(p.date); }).join('\n');
    } catch (e) {}
    alert('Receipt ' + s.id + '\nCustomer: ' + GMS.customerName(s.customerId) + '\nDate: ' + GMS.formatDate(s.date) + '\n\n' + lines + '\n\nTotal: ' + GMS.formatMoney(total) + '\nCash received: ' + GMS.formatMoney(s.cashReceived) + '\nDue: ' + GMS.formatMoney(due) + payText);
  };

  // init — new sale starts empty, right-side receipt stays ৳0.00 by default.
  document.getElementById('saleCustomer').innerHTML = '<option value="">Walk-in customer</option>' + await customerOptions();
  document.getElementById('saleDate').value = GMS.todayISO();
  lineItemsBody.innerHTML = '';
  resetReceipt();
  renderHistory();
});
