document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const backdrop = document.getElementById('modalBackdrop');
  const form = document.getElementById('customerForm');
  let searchTerm = '';

  async function render() {
    const term = searchTerm.toLowerCase();
    const all = await GMS.customers.all();
    const rows = all.filter(function (c) {
      return c.name.toLowerCase().includes(term) || (c.phone || '').includes(term) || (c.area || '').toLowerCase().includes(term);
    });

    const body = document.getElementById('tableBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="6">No customers found. Walk-in sales don\'t need a customer record.</td></tr>';
      return;
    }

    body.innerHTML = rows.map(function (c) {
      // Cashier is view + add only: no Edit/Delete buttons (backend blocks with 403 too).
      const isCashier = (typeof GMS !== 'undefined' && GMS.session.role() === 'cashier');
      const actions = isCashier ? '<span class="muted-cell">—</span>' :
          '<button class="btn btn-ghost btn-sm" onclick="editCustomer(\'' + c.id + '\')">Edit</button>' +
          '<button class="btn btn-danger btn-sm" onclick="deleteCustomer(\'' + c.id + '\')">Delete</button>';
      return '<tr>' +
        '<td class="muted-cell">' + c.id + '</td>' +
        '<td>' + c.name + '</td>' +
        '<td>' + (c.phone || '\u2014') + '</td>' +
        '<td>' + (c.area || '\u2014') + '</td>' +
        '<td class="cell-num">' + (c.purchaseCount != null ? c.purchaseCount : '\u2014') + '</td>' +
        '<td class="row-actions">' + actions + '</td></tr>';
    }).join('');
  }

  function openModal(title) { document.getElementById('modalTitle').textContent = title; backdrop.classList.add('open'); }
  function closeModal() { backdrop.classList.remove('open'); form.reset(); document.getElementById('customerId').value = ''; }

  document.getElementById('addBtn').addEventListener('click', function () { form.reset(); document.getElementById('customerId').value = ''; openModal('Add customer'); });
  document.getElementById('modalClose').addEventListener('click', closeModal);
  document.getElementById('cancelBtn').addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
  document.getElementById('searchInput').addEventListener('input', function (e) { searchTerm = e.target.value; render(); });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const name = document.getElementById('customerName').value.trim();
    const phone = document.getElementById('customerPhone').value.trim();
    const area = document.getElementById('customerArea').value.trim();
    const err = GMS.validate.name(name, 'Customer name', 150)
      || GMS.validate.phone(phone, 'Phone')
      || (area.length > 100 ? 'Area is too long (max 100 characters).' : null);
    if (err) { GMSApp.showToast(err, true); return; }

    try {
      const id = document.getElementById('customerId').value;
      if (id) {
        await GMS.customers.update(Number(id), { name: name, phone: phone, area: area });
        GMSApp.showToast('Customer updated in database.');
      } else {
        await GMS.customers.add({ name: name, phone: phone, area: area });
        GMSApp.showToast('Customer added to database.');
      }
      closeModal();
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

  window.editCustomer = async function (id) {
    if (typeof GMS !== 'undefined' && GMS.session.role() === 'cashier') { GMSApp.showToast('Cashier cannot edit customers.', true); return; }
    const c = await GMS.customers.get(Number(id));
    if (!c) return;
    document.getElementById('customerId').value = c.id;
    document.getElementById('customerName').value = c.name;
    document.getElementById('customerPhone').value = c.phone || '';
    document.getElementById('customerArea').value = c.area || '';
    openModal('Edit customer');
  };

  window.deleteCustomer = async function (id) {
    if (typeof GMS !== 'undefined' && GMS.session.role() === 'cashier') { GMSApp.showToast('Cashier cannot delete customers.', true); return; }
    if (!confirm('Delete this customer? This cannot be undone.')) return;
    try {
      await GMS.customers.remove(Number(id));
      GMSApp.showToast('Customer deleted.');
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  };

  render();
});
