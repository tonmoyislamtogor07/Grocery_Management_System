document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const backdrop = document.getElementById('modalBackdrop');
  const form = document.getElementById('productForm');
  let searchTerm = '';
  let categoryFilter = '';
  let statusFilter = '';

  async function categoryOptions(selectedId) {
    const cats = await GMS.categories.all();
    return cats.map(function (c) {
      return '<option value="' + c.id + '"' + (Number(c.id) === Number(selectedId) ? ' selected' : '') + '>' + c.name + '</option>';
    }).join('');
  }

  // Option B: multiple suppliers per product (checkbox list, optional).
  async function supplierCheckboxes(selectedIds) {
    const sups = await GMS.suppliers.all();
    const sel = (selectedIds || []).map(Number);
    if (sups.length === 0) return '<span class="muted-cell">No suppliers yet — add one first.</span>';
    return sups.map(function (s) {
      const checked = sel.indexOf(Number(s.id)) !== -1 ? ' checked' : '';
      return '<label><input type="checkbox" class="supplier-check" value="' + s.id + '"' + checked + '> ' + s.name + '</label>';
    }).join('');
  }

  function selectedSupplierIds() {
    return Array.prototype.map.call(
      document.querySelectorAll('#productSuppliers .supplier-check:checked'),
      function (el) { return Number(el.value); }
    ).filter(function (n) { return !isNaN(n) && n > 0; });
  }

  async function populateFilters() {
    document.getElementById('categoryFilter').insertAdjacentHTML('beforeend', await categoryOptions(''));
    document.getElementById('productCategory').innerHTML = await categoryOptions('');
    document.getElementById('productSuppliers').innerHTML = await supplierCheckboxes([]);
  }

  function statusBadge(status) {
    if (status === 'out') return '<span class="badge badge-out">Out of stock</span>';
    if (status === 'low') return '<span class="badge badge-low">Low stock</span>';
    return '<span class="badge badge-ok">In stock</span>';
  }

  async function render() {
    const term = searchTerm.toLowerCase();
    const all = await GMS.products.all();
    let rows = all.filter(function (p) { return p.name.toLowerCase().includes(term); });
    if (categoryFilter) rows = rows.filter(function (p) { return String(p.categoryId) === String(categoryFilter); });
    if (statusFilter) rows = rows.filter(function (p) { return GMS.stockStatus(p) === statusFilter; });

    const body = document.getElementById('tableBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="9">No products match your filters.</td></tr>';
      return;
    }

    body.innerHTML = rows.map(function (p) {
      const status = GMS.stockStatus(p);
      const rowClass = status === 'out' ? 'row-danger' : status === 'low' ? 'row-warn' : '';
      const expDays = GMS.daysUntil(p.expiry);
      const expiryText = GMS.formatDate(p.expiry) + (expDays !== null && expDays <= 30 ? ' <span class="text-muted">(' + (expDays < 0 ? 'expired' : expDays + 'd left') + ')</span>' : '');
      const supText = GMS.supplierNames ? GMS.supplierNames(p.supplierIds || []) : '';
      return '<tr class="' + rowClass + '">' +
        '<td class="muted-cell">' + p.id + '</td>' +
        '<td>' + p.name + '</td>' +
        '<td>' + GMS.categoryName(p.categoryId) + '</td>' +
        '<td>' + supText + '</td>' +
        '<td class="cell-num">' + GMS.formatMoney(p.price) + '</td>' +
        '<td class="cell-num">' + p.stock + '</td>' +
        '<td>' + statusBadge(status) + '</td>' +
        '<td>' + expiryText + '</td>' +
        '<td class="row-actions">' +
          '<button class="btn btn-ghost btn-sm" onclick="editProduct(\'' + p.id + '\')">Edit</button>' +
          '<button class="btn btn-danger btn-sm" onclick="deleteProduct(\'' + p.id + '\')">Delete</button>' +
        '</td></tr>';
    }).join('');
  }

  function openModal(title) { document.getElementById('modalTitle').textContent = title; backdrop.classList.add('open'); }
  function closeModal() { backdrop.classList.remove('open'); form.reset(); document.getElementById('productId').value = ''; }

  document.getElementById('addBtn').addEventListener('click', async function () {
    form.reset();
    document.getElementById('productId').value = '';
    document.getElementById('productCategory').innerHTML = await categoryOptions('');
    document.getElementById('productSuppliers').innerHTML = await supplierCheckboxes([]);
    if ((await GMS.categories.all()).length === 0) {
      GMSApp.showToast('Add a category first before adding products.', true);
      return;
    }
    openModal('Add product');
  });
  document.getElementById('modalClose').addEventListener('click', closeModal);
  document.getElementById('cancelBtn').addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });

  document.getElementById('searchInput').addEventListener('input', function (e) { searchTerm = e.target.value; render(); });
  document.getElementById('categoryFilter').addEventListener('change', function (e) { categoryFilter = e.target.value; render(); });
  document.getElementById('statusFilter').addEventListener('change', function (e) { statusFilter = e.target.value; render(); });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const name = document.getElementById('productName').value;
    const categoryId = document.getElementById('productCategory').value;
    const priceRaw = document.getElementById('productPrice').value;
    const purchasePriceRaw = document.getElementById('productPurchasePrice').value;
    const stockRaw = document.getElementById('productStock').value;
    const reorderRaw = document.getElementById('productReorder').value;
    const expiry = document.getElementById('productExpiry').value;

    let err = GMS.validate.name(name, 'Product name', 200)
      || (!categoryId ? 'Please choose a category.' : null)
      || GMS.validate.money(priceRaw, 'Selling price')
      || GMS.validate.money(purchasePriceRaw, 'Purchase price')
      || GMS.validate.qty(stockRaw, 'Stock', 0)
      || (reorderRaw !== '' ? GMS.validate.qty(reorderRaw, 'Reorder level', 0) : null)
      || (expiry ? GMS.validate.date(expiry, 'Expiry date') : null);
    if (err) { GMSApp.showToast(err, true); return; }

    const price = parseFloat(priceRaw);
    const purchasePrice = parseFloat(purchasePriceRaw);
    const stock = parseInt(stockRaw, 10);
    const reorderLevel = reorderRaw === '' ? 5 : parseInt(reorderRaw, 10);

    const data = {
      name: name, categoryId: Number(categoryId), price: price, purchasePrice: purchasePrice, stock: stock,
<<<<<<< HEAD
      reorderLevel: isNaN(reorderLevel) ? 5 : reorderLevel, expiry: expiry || ''
=======
      reorderLevel: isNaN(reorderLevel) ? 5 : reorderLevel, expiry: expiry || '',
      supplierIds: selectedSupplierIds()
>>>>>>> new1.0
    };

    try {
      const id = document.getElementById('productId').value;
      if (id) {
        await GMS.products.update(Number(id), data);
        GMSApp.showToast('Product updated in database.');
      } else {
        await GMS.products.add(data);
        GMSApp.showToast('Product added to database.');
      }
      closeModal();
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

  window.editProduct = async function (id) {
    const p = await GMS.products.get(Number(id));
    if (!p) return;
    document.getElementById('productId').value = p.id;
    document.getElementById('productName').value = p.name;
    document.getElementById('productCategory').innerHTML = await categoryOptions(p.categoryId);
    document.getElementById('productPrice').value = p.price;
    document.getElementById('productPurchasePrice').value = (p.purchasePrice !== undefined && p.purchasePrice !== null) ? p.purchasePrice : p.price;
<<<<<<< HEAD
=======
    document.getElementById('productSuppliers').innerHTML = await supplierCheckboxes(p.supplierIds || (p.supplierId ? [p.supplierId] : []));
>>>>>>> new1.0
    document.getElementById('productStock').value = p.stock;
    document.getElementById('productReorder').value = p.reorderLevel;
    document.getElementById('productExpiry').value = p.expiry || '';
    openModal('Edit product');
  };

  window.deleteProduct = async function (id) {
    try {
      await GMS.products.remove(Number(id));
      GMSApp.showToast('Product deleted.');
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  };

  await populateFilters();
  render();
});
