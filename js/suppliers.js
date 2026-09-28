document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const backdrop = document.getElementById('modalBackdrop');
  const form = document.getElementById('supplierForm');
  let searchTerm = '';

  async function render() {
    const term = searchTerm.toLowerCase();
    const all = await GMS.suppliers.all();
    const rows = all.filter(function (s) {
      return s.name.toLowerCase().includes(term) || (s.phone || '').includes(term);
    });

    const body = document.getElementById('tableBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="6">No suppliers found. Add a supplier to start recording purchases.</td></tr>';
      return;
    }

    body.innerHTML = rows.map(function (s) {
      return '<tr>' +
        '<td class="muted-cell">' + s.id + '</td>' +
        '<td>' + s.name + '</td>' +
        '<td>' + (s.phone || '\u2014') + '</td>' +
        '<td>' + (s.address || '\u2014') + '</td>' +
        '<td class="cell-num">' + (s.purchaseCount != null ? s.purchaseCount : '\u2014') + '</td>' +
        '<td class="row-actions">' +
          '<button class="btn btn-ghost btn-sm" onclick="editSupplier(\'' + s.id + '\')">Edit</button>' +
          '<button class="btn btn-danger btn-sm" onclick="deleteSupplier(\'' + s.id + '\')">Delete</button>' +
        '</td></tr>';
    }).join('');
  }

  function openModal(title) { document.getElementById('modalTitle').textContent = title; backdrop.classList.add('open'); }
  function closeModal() { backdrop.classList.remove('open'); form.reset(); document.getElementById('supplierId').value = ''; }

  document.getElementById('addBtn').addEventListener('click', function () { form.reset(); document.getElementById('supplierId').value = ''; openModal('Add supplier'); });
  document.getElementById('modalClose').addEventListener('click', closeModal);
  document.getElementById('cancelBtn').addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
  document.getElementById('searchInput').addEventListener('input', function (e) { searchTerm = e.target.value; render(); });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const name = document.getElementById('supplierName').value.trim();
    const phone = document.getElementById('supplierPhone').value.trim();
    const address = document.getElementById('supplierAddress').value.trim();
    const err = GMS.validate.name(name, 'Supplier name', 150)
      || GMS.validate.phone(phone, 'Phone')
      || (address.length > 1000 ? 'Address is too long (max 1000 characters).' : null);
    if (err) { GMSApp.showToast(err, true); return; }

    try {
      const id = document.getElementById('supplierId').value;
      if (id) {
        await GMS.suppliers.update(Number(id), { name: name, phone: phone, address: address });
        GMSApp.showToast('Supplier updated in database.');
      } else {
        await GMS.suppliers.add({ name: name, phone: phone, address: address });
        GMSApp.showToast('Supplier added to database.');
      }
      closeModal();
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

  window.editSupplier = async function (id) {
    const s = await GMS.suppliers.get(Number(id));
    if (!s) return;
    document.getElementById('supplierId').value = s.id;
    document.getElementById('supplierName').value = s.name;
    document.getElementById('supplierPhone').value = s.phone || '';
    document.getElementById('supplierAddress').value = s.address || '';
    openModal('Edit supplier');
  };

  window.deleteSupplier = async function (id) {
    if (!confirm('Delete this supplier? This cannot be undone.')) return;
    try {
      await GMS.suppliers.remove(Number(id));
      GMSApp.showToast('Supplier deleted.');
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  };

  render();
});
