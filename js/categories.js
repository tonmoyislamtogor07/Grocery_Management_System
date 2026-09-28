document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const backdrop = document.getElementById('modalBackdrop');
  const form = document.getElementById('categoryForm');
  let searchTerm = '';

  async function productCount(categoryId) {
    const all = await GMS.products.all();
    return all.filter(function (p) { return String(p.categoryId) === String(categoryId); }).length;
  }

  async function render() {
    const all = await GMS.categories.all();
    const rows = all.filter(function (c) { return c.name.toLowerCase().includes(searchTerm.toLowerCase()); });

    const body = document.getElementById('tableBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="4">No categories found. Add your first category to start organizing products.</td></tr>';
      return;
    }

    const prods = await GMS.products.all();
    body.innerHTML = rows.map(function (c) {
      const n = prods.filter(function (p) { return String(p.categoryId) === String(c.id); }).length;
      return '<tr>' +
        '<td class="muted-cell">' + c.id + '</td>' +
        '<td>' + c.name + '</td>' +
        '<td class="cell-num">' + n + '</td>' +
        '<td class="row-actions">' +
          '<button class="btn btn-ghost btn-sm" onclick="editCategory(\'' + c.id + '\')">Edit</button>' +
          '<button class="btn btn-danger btn-sm" onclick="deleteCategory(\'' + c.id + '\')">Delete</button>' +
        '</td></tr>';
    }).join('');
  }

  function openModal(title) {
    document.getElementById('modalTitle').textContent = title;
    backdrop.classList.add('open');
  }
  function closeModal() {
    backdrop.classList.remove('open');
    form.reset();
    document.getElementById('categoryId').value = '';
  }

  document.getElementById('addBtn').addEventListener('click', function () {
    form.reset();
    document.getElementById('categoryId').value = '';
    openModal('Add category');
  });
  document.getElementById('modalClose').addEventListener('click', closeModal);
  document.getElementById('cancelBtn').addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });

  document.getElementById('searchInput').addEventListener('input', function (e) {
    searchTerm = e.target.value;
    render();
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const name = document.getElementById('categoryName').value.trim();
    const err = GMS.validate.name(name, 'Category name', 100);
    if (err) { GMSApp.showToast(err, true); return; }

    try {
      const id = document.getElementById('categoryId').value;
      if (id) {
        await GMS.categories.update(Number(id), { name: name });
        GMSApp.showToast('Category updated in database.');
      } else {
        await GMS.categories.add({ name: name });
        GMSApp.showToast('Category added to database.');
      }
      closeModal();
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

  window.editCategory = async function (id) {
    const c = await GMS.categories.get(Number(id));
    if (!c) return;
    document.getElementById('categoryId').value = c.id;
    document.getElementById('categoryName').value = c.name;
    openModal('Edit category');
  };

  window.deleteCategory = async function (id) {
    if (await productCount(id) > 0) {
      GMSApp.showToast('Cannot delete: products are still assigned to this category.', true);
      return;
    }
    if (!confirm('Delete this category? This cannot be undone.')) return;
    try {
      await GMS.categories.remove(Number(id));
      GMSApp.showToast('Category deleted.');
      render();
    } catch (err) { GMSApp.showToast(err.message, true); }
  };

  render();
});
