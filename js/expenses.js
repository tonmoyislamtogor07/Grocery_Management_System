document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const backdrop = document.getElementById('modalBackdrop');
  const form = document.getElementById('expenseForm');
  let searchTerm = '';
  let categoryFilter = '';

  async function renderStats() {
    const all = await GMS.expenses.all();
    const total = all.reduce(function (s, e) { return s + (Number(e.amount) || 0); }, 0);
    const thisMonth = GMS.todayISO().slice(0, 7);
    const monthTotal = all.filter(function (e) { return (e.date || '').slice(0, 7) === thisMonth; }).reduce(function (s, e) { return s + Number(e.amount); }, 0);
    const byCategory = {};
    all.forEach(function (e) { byCategory[e.category] = (byCategory[e.category] || 0) + Number(e.amount); });
    const topCategory = Object.entries(byCategory).sort(function (a, b) { return b[1] - a[1]; })[0];

    document.getElementById('statRow').innerHTML =
      '<div class="stat accent-turmeric"><div class="label">This month\'s expenses</div><div class="value">' + GMS.formatMoney(monthTotal) + '</div></div>' +
      '<div class="stat accent-green"><div class="label">All-time expenses</div><div class="value">' + GMS.formatMoney(total) + '</div></div>' +
      '<div class="stat accent-green"><div class="label">Top category</div><div class="value stat-value--sm">' + (topCategory ? topCategory[0] : '\u2014') + '</div></div>';
  }

  async function render() {
    const term = searchTerm.toLowerCase();
    const all = await GMS.expenses.all();
    let rows = all.filter(function (e) { return (e.description || '').toLowerCase().includes(term); });
    if (categoryFilter) rows = rows.filter(function (e) { return e.category === categoryFilter; });
    rows = rows.slice().sort(function (a, b) { return b.date.localeCompare(a.date); });

    const body = document.getElementById('tableBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="6">No expenses found.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (e) {
      return '<tr>' +
        '<td class="muted-cell">' + e.id + '</td>' +
        '<td>' + GMS.formatDate(e.date) + '</td>' +
        '<td>' + e.category + '</td>' +
        '<td>' + (e.description || '\u2014') + '</td>' +
        '<td class="cell-num">' + GMS.formatMoney(e.amount) + '</td>' +
        '<td class="row-actions">' +
          '<button class="btn btn-ghost btn-sm" onclick="editExpense(\'' + e.id + '\')">Edit</button>' +
          '<button class="btn btn-danger btn-sm" onclick="deleteExpense(\'' + e.id + '\')">Delete</button>' +
        '</td></tr>';
    }).join('');
  }

  function openModal(title) { document.getElementById('modalTitle').textContent = title; backdrop.classList.add('open'); }
  function closeModal() { backdrop.classList.remove('open'); form.reset(); document.getElementById('expenseId').value = ''; }

  document.getElementById('addBtn').addEventListener('click', function () {
    form.reset();
    document.getElementById('expenseId').value = '';
    document.getElementById('expenseDate').value = GMS.todayISO();
    openModal('Add expense');
  });
  document.getElementById('modalClose').addEventListener('click', closeModal);
  document.getElementById('cancelBtn').addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
  document.getElementById('searchInput').addEventListener('input', function (e) { searchTerm = e.target.value; render(); });
  document.getElementById('categoryFilter').addEventListener('change', function (e) { categoryFilter = e.target.value; render(); });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const category = document.getElementById('expenseCategory').value;
    const date = document.getElementById('expenseDate').value;
    const description = document.getElementById('expenseDescription').value.trim();
    const amountRaw = document.getElementById('expenseAmount').value;
    const amount = parseFloat(amountRaw);

    const err = GMS.validate.date(date, 'Expense date')
      || GMS.validate.money(amountRaw, 'Amount', true)
      || (description.length > 2000 ? 'Description is too long (max 2000 characters).' : null);
    if (err) { GMSApp.showToast(err, true); return; }

    try {
      const data = { category: category, date: date, description: description, amount: amount };
      const id = document.getElementById('expenseId').value;
      if (id) {
        await GMS.expenses.update(Number(id), data);
        GMSApp.showToast('Expense updated in database.');
      } else {
        await GMS.expenses.add(data);
        GMSApp.showToast('Expense added to database.');
      }
      closeModal();
      render();
      renderStats();
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

  window.editExpense = async function (id) {
    const list = await GMS.expenses.all();
    const x = list.find(function (v) { return String(v.id) === String(id); });
    if (!x) return;
    document.getElementById('expenseId').value = x.id;
    document.getElementById('expenseCategory').value = x.category;
    document.getElementById('expenseDate').value = x.date;
    document.getElementById('expenseDescription').value = x.description || '';
    document.getElementById('expenseAmount').value = x.amount;
    openModal('Edit expense');
  };

  window.deleteExpense = async function (id) {
    if (!confirm('Delete this expense entry? This cannot be undone.')) return;
    try {
      await GMS.expenses.remove(Number(id));
      GMSApp.showToast('Expense deleted.');
      render();
      renderStats();
    } catch (err) { GMSApp.showToast(err.message, true); }
  };

  renderStats();
  render();
});
