document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const backdrop = document.getElementById('modalBackdrop');
  const form = document.getElementById('purchaseForm');
  const lineItemsBody = document.getElementById('lineItemsBody');
  let searchTerm = '';
  let lineCounter = 0;

  async function supplierOptions() {
    const all = await GMS.suppliers.all();
    return all.map(function (s) { return '<option value="' + s.id + '">' + s.name + '</option>'; }).join('');
  }

  async function productOptions(selectedId) {
    const all = await GMS.products.all();
    return all.map(function (p) {
      return '<option value="' + p.id + '" data-price="' + p.price + '"' + (String(p.id) === String(selectedId) ? ' selected' : '') + '>' + p.name + '</option>';
    }).join('');
  }

  async function render() {
    const term = searchTerm.toLowerCase();
    const all = await GMS.purchases.all();
    const rows = all
      .filter(function (p) { return GMS.supplierName(p.supplierId).toLowerCase().includes(term) || String(p.id).toLowerCase().includes(term); })
      .sort(function (a, b) { return b.date.localeCompare(a.date); });

    const body = document.getElementById('tableBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="6">No purchases recorded yet.</td></tr>';
      return;
    }

    body.innerHTML = rows.map(function (p) {
      return '<tr>' +
        '<td class="muted-cell">' + p.id + '</td>' +
        '<td>' + GMS.supplierName(p.supplierId) + '</td>' +
        '<td>' + GMS.formatDate(p.date) + '</td>' +
        '<td class="cell-num">' + p.items.length + '</td>' +
        '<td class="cell-num">' + GMS.formatMoney(GMS.docTotal(p)) + '</td>' +
        '<td class="row-actions"><button class="btn btn-ghost btn-sm" onclick="viewPurchase(\'' + p.id + '\')">View</button></td>' +
        '</tr>';
    }).join('');
  }

  async function addLineRow(productId, qty, price) {
    lineCounter++;
    const rowId = 'line' + lineCounter;
    const row = document.createElement('tr');
    row.id = rowId;
    row.innerHTML =
      '<td><select class="line-product">' + await productOptions(productId) + '</select></td>' +
      '<td><input type="number" class="line-qty" min="1" step="1" value="' + (qty || 1) + '"></td>' +
      '<td><input type="number" class="line-price" min="0" step="0.01" value="' + (price != null ? price : '') + '"></td>' +
      '<td class="cell-num line-total">৳0.00</td>' +
      '<td><button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById(\'' + rowId + '\').remove(); recalcTotal();">&times;</button></td>';
    lineItemsBody.appendChild(row);

    const select = row.querySelector('.line-product');
    const priceInput = row.querySelector('.line-price');
    if (!price && select.options.length) {
      priceInput.value = select.options[select.selectedIndex] ? (select.options[select.selectedIndex].dataset.price || 0) : 0;
    }
    select.addEventListener('change', function () {
      const opt = select.options[select.selectedIndex];
      priceInput.value = opt ? (opt.dataset.price || 0) : 0;
      recalcTotal();
    });
    row.querySelector('.line-qty').addEventListener('input', recalcTotal);
    priceInput.addEventListener('input', recalcTotal);
    recalcTotal();
  }

  function recalcTotal() {
    let total = 0;
    lineItemsBody.querySelectorAll('tr').forEach(function (row) {
      const qty = parseFloat(row.querySelector('.line-qty').value) || 0;
      const price = parseFloat(row.querySelector('.line-price').value) || 0;
      const lineTotal = qty * price;
      row.querySelector('.line-total').textContent = GMS.formatMoney(lineTotal);
      total += lineTotal;
    });
    document.getElementById('purchaseTotal').textContent = GMS.formatMoney(total);
  }
  window.recalcTotal = recalcTotal;

  function openModal() { backdrop.classList.add('open'); }
  function closeModal() { backdrop.classList.remove('open'); form.reset(); lineItemsBody.innerHTML = ''; }

  document.getElementById('newPurchaseBtn').addEventListener('click', async function () {
    if ((await GMS.suppliers.all()).length === 0) { GMSApp.showToast('Add a supplier first.', true); return; }
    if ((await GMS.products.all()).length === 0) { GMSApp.showToast('Add products first.', true); return; }
    document.getElementById('purchaseSupplier').innerHTML = await supplierOptions();
    document.getElementById('purchaseDate').value = GMS.todayISO();
    lineItemsBody.innerHTML = '';
    addLineRow();
    openModal();
  });
  document.getElementById('modalClose').addEventListener('click', closeModal);
  document.getElementById('cancelBtn').addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
  document.getElementById('addLineBtn').addEventListener('click', function () { addLineRow(); });
  document.getElementById('searchInput').addEventListener('input', function (e) { searchTerm = e.target.value; render(); });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const supplierId = document.getElementById('purchaseSupplier').value;
    const date = document.getElementById('purchaseDate').value;
    let err = (!supplierId ? 'Choose a supplier.' : null) || GMS.validate.date(date, 'Purchase date');
    if (err) { GMSApp.showToast(err, true); return; }

    const items = [];
    lineItemsBody.querySelectorAll('tr').forEach(function (row) {
      if (err) return;
      const productId = Number(row.querySelector('.line-product').value);
      const qtyRaw = row.querySelector('.line-qty').value;
      const priceRaw = row.querySelector('.line-price').value;
      err = (!productId ? 'Every row needs a product.' : null)
        || GMS.validate.qty(qtyRaw, 'Quantity')
        || GMS.validate.money(priceRaw, 'Unit price');
      if (!err) items.push({ productId: productId, qty: parseInt(qtyRaw, 10), price: parseFloat(priceRaw) });
    });

    if (err || items.length === 0) { GMSApp.showToast(err || 'Add at least one item.', true); return; }

    try {
      // Saved to purchases + purchase_details; stock auto-increases in PHP.
      await GMS.purchases.add({ supplierId: Number(supplierId), date: date, items: items });
      GMSApp.showToast('Purchase saved in database and stock updated.');
      closeModal();
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

  window.viewPurchase = async function (id) {
    const list = await GMS.purchases.all();
    const p = list.find(function (v) { return String(v.id) === String(id); });
    if (!p) return;
    const prodList = await GMS.products.all();
    const lines = p.items.map(function (it) {
      const prod = prodList.find(function (x) { return String(x.id) === String(it.productId); });
      return '- ' + (prod ? prod.name : it.productId) + '  x' + it.qty + '  @ ' + GMS.formatMoney(it.price);
    }).join('\n');
    alert('Purchase ' + p.id + '\nSupplier: ' + GMS.supplierName(p.supplierId) + '\nDate: ' + GMS.formatDate(p.date) + '\n\n' + lines + '\n\nTotal: ' + GMS.formatMoney(GMS.docTotal(p)));
  };

  render();
});
