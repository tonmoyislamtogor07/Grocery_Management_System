/* staff.js — Staff & Access: hire/edit/deactivate accounts + audit log.
   The real permission matrix lives in api/users.php; this file only hides
   buttons the signed-in role may not use. */
document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const myRole = GMS.session.role() || 'owner';
  const myUsername = GMS.session.current() || '';
  const ROLE_LABEL = { owner: 'Owner', manager: 'Manager', cashier: 'Cashier' };
  const ACTION_LABEL = {
    created: 'Hired', updated: 'Edited', role_changed: 'Role changed',
    activated: 'Reactivated', deactivated: 'Deactivated', deleted: 'Deleted',
    password_reset: 'Password reset', unlocked: 'Unlocked'
  };
  const ACTION_BADGE = {
    created: 'badge-ok', activated: 'badge-ok', unlocked: 'badge-ok',
    updated: 'badge-low', role_changed: 'badge-low', password_reset: 'badge-low',
    deactivated: 'badge-out', deleted: 'badge-out'
  };

  const backdrop = document.getElementById('modalBackdrop');
  const form = document.getElementById('staffForm');
  const roleSelect = document.getElementById('staffRole');
  const addBtn = document.getElementById('addBtn');
  let accounts = [];
  let searchTerm = '';

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function roleTag(key) {
    if (!key) return '';
    return '<span class="role-tag role-' + esc(key) + '">' + esc(ROLE_LABEL[key] || key) + '</span>';
  }
  function formatWhen(value) {
    if (!value) return '\u2014';
    const d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d)) return String(value);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
      ', ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  }

  /* ---------------- permissions shown in the UI ---------------- */
  function isSelf(s) { return s.username === myUsername; }
  function canEdit(s) { return myRole === 'owner' || (myRole === 'manager' && s.role === 'cashier'); }
  function canToggle(s) { return !isSelf(s) && canEdit(s); }

  if (myRole === 'manager') {
    const notice = document.getElementById('roleNotice');
    notice.hidden = false;
    notice.innerHTML = 'Manager access: you can hire Cashier accounts and edit or deactivate them. ' +
      'Permanent delete and role changes stay with the Owner, and Owner/Manager accounts are not shown here.';
  }
  addBtn.textContent = myRole === 'manager' ? '+ Hire cashier' : '+ Add staff';

  /* ---------------- render ---------------- */
  function renderAccounts() {
    const term = searchTerm.toLowerCase();
    const rows = accounts.filter(function (s) {
      return !term
        || (s.fullName || '').toLowerCase().includes(term)
        || (s.username || '').toLowerCase().includes(term)
        || (ROLE_LABEL[s.role] || '').toLowerCase().includes(term);
    });

    const body = document.getElementById('tableBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="8">' +
        (accounts.length === 0 ? 'No staff accounts yet. Use “Add staff” to hire your first cashier.' : 'No accounts match your search.') +
        '</td></tr>';
      return;
    }

    body.innerHTML = rows.map(function (s) {
      return '<tr>' +
        '<td>' + esc(s.fullName || '\u2014') + (isSelf(s) ? ' <span class="you-chip">you</span>' : '') + '</td>' +
        '<td class="mono-cell">@' + esc(s.username) + '</td>' +
        '<td>' + roleTag(s.role) + '</td>' +
        '<td class="muted-cell">' + (s.email
          ? esc(s.email)
          : '<span class="text-muted" title="No email on file: this account cannot use “Forgot password?”.">no email</span>') + '</td>' +
        '<td><span class="badge ' + (s.isActive ? 'badge-ok' : 'badge-out') + '">' + (s.isActive ? 'Active' : 'Deactivated') + '</span>' +
          lockBadge(s) + '</td>' +
        '<td class="cell-num">' + (s.activity || 0) + '</td>' +
        '<td class="muted-cell">' + (s.createdAt ? GMS.formatDate(s.createdAt) : '\u2014') + '</td>' +
        '<td>' + rowActions(s) + '</td>' +
        '</tr>';
    }).join('');
  }

  function lockBadge(s) {
    if (!s.locked) return '';
    return ' <span class="badge badge-out" title="Too many wrong passwords. Logins are blocked for about ' +
      s.lockMinutes + ' more minute(s).">Locked \u00b7 ' + s.lockMinutes + 'm</span>';
  }

  function rowActions(s) {
    let html = '';
    if (s.locked && canEdit(s)) {
      html += '<button class="btn btn-accent btn-sm" data-act="unlock" data-id="' + s.id + '">Unlock</button>';
    }
    if (canEdit(s)) {
      html += '<button class="btn btn-ghost btn-sm" data-act="edit" data-id="' + s.id + '">Edit</button>';
    }
    if (canToggle(s)) {
      html += '<button class="btn btn-ghost btn-sm" data-act="' + (s.isActive ? 'deactivate' : 'activate') + '" data-id="' + s.id + '">' +
        (s.isActive ? 'Deactivate' : 'Activate') + '</button>';
    }
    if (myRole === 'owner' && !isSelf(s) && s.role !== 'owner') {
      const blocked = (s.activity || 0) > 0;
      html += '<button class="btn btn-danger btn-sm" data-act="delete" data-id="' + s.id + '"' +
        (blocked ? ' disabled title="This account has sale/purchase/expense history — deactivate it instead."' : '') +
        '>Delete</button>';
    }
    return html ? '<div class="row-actions">' + html + '</div>' : '<span class="text-muted">\u2014</span>';
  }

  function renderLog(log) {
    const body = document.getElementById('logBody');
    if (!log || log.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="5">No account changes recorded yet.</td></tr>';
      return;
    }
    body.innerHTML = log.map(function (l) {
      const action = ACTION_LABEL[l.action] || l.action;
      return '<tr>' +
        '<td class="muted-cell">' + formatWhen(l.at) + '</td>' +
        '<td>' + esc(l.actorName || l.actorUsername || '\u2014') + '<div class="sub-line">' + roleTag(l.actorRole) + '</div></td>' +
        '<td><span class="badge ' + (ACTION_BADGE[l.action] || 'badge-low') + '">' + esc(action) + '</span></td>' +
        '<td>' + esc(l.targetName || '\u2014') +
          (l.targetUsername ? '<div class="sub-line mono-cell">@' + esc(l.targetUsername) + '</div>' : '') + '</td>' +
        '<td class="muted-cell">' + esc(l.details || '\u2014') + '</td>' +
        '</tr>';
    }).join('');
  }

  /* ---------------- password approvals (Manager / Owner) ---------------- */
  function renderApprovals(sec) {
    const panel = document.getElementById('approvalPanel');
    if (!sec || (sec.role !== 'owner' && sec.role !== 'manager')) { panel.hidden = true; return; }
    panel.hidden = false;

    const rows = sec.pending || [];
    document.getElementById('approvalCount').textContent = rows.length
      ? rows.length + (rows.length === 1 ? ' request waiting' : ' requests waiting')
      : '';

    const body = document.getElementById('approvalBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr class="empty-row"><td colspan="5">No password change is waiting for you.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (r) {
      const mine = r.username === myUsername;
      return '<tr>' +
        '<td class="muted-cell">' + formatWhen(r.requestedAt) + '</td>' +
        '<td>' + esc(r.fullName || '\u2014') +
          '<div class="sub-line mono-cell">@' + esc(r.username) + '</div></td>' +
        '<td>' + roleTag(r.role) + '</td>' +
        '<td class="muted-cell">' + (r.mode === 'forgot'
          ? 'Forgot password \u00b7 emailed code'
          : 'Signed in \u00b7 current password') + '</td>' +
        '<td>' + (mine
          ? '<span class="text-muted">Your own request</span>'
          : '<div class="row-actions">' +
              '<button class="btn btn-primary btn-sm" data-approve="' + r.id + '">Approve</button>' +
              '<button class="btn btn-ghost btn-sm" data-reject="' + r.id + '">Reject</button>' +
            '</div>') + '</td>' +
        '</tr>';
    }).join('');
  }

  async function load() {
    try {
      const results = await Promise.all([GMS.staff.all(), GMS.staff.log(), GMS.account.list()]);
      accounts = results[0] || [];
      renderAccounts();
      renderLog(results[1] || []);
      renderApprovals(results[2] || null);
    } catch (err) {
      GMSApp.showToast(err.message, true);
    }
  }

  /* ---------------- modal ---------------- */
  function fillRoles(selected, locked) {
    let opts = myRole === 'manager' ? ['cashier'] : ['cashier', 'manager'];
    if (selected && opts.indexOf(selected) === -1) opts = [selected].concat(opts);
    roleSelect.innerHTML = opts.map(function (k) {
      return '<option value="' + k + '">' + ROLE_LABEL[k] + '</option>';
    }).join('');
    roleSelect.value = selected || opts[0];
    roleSelect.disabled = !!locked;
  }

  function openModal(title) {
    document.getElementById('modalTitle').textContent = title;
    backdrop.classList.add('open');
  }
  function closeModal() {
    backdrop.classList.remove('open');
    form.reset();
    document.getElementById('staffId').value = '';
  }

  function openCreate() {
    form.reset();
    document.getElementById('staffId').value = '';
    document.getElementById('staffFullName').value = '';
    document.getElementById('staffPassword').value = '';
    const username = document.getElementById('staffUsername');
    username.value = '';
    username.readOnly = false;
    document.getElementById('usernameHint').textContent = '3\u201350 characters: letters, digits, dot, underscore.';
    document.getElementById('staffEmail').value = '';
    document.getElementById('passwordLabel').textContent = 'Password';
    document.getElementById('passwordHint').textContent = 'They sign in with this username and password.';
    fillRoles('cashier', myRole === 'manager');
    openModal(myRole === 'manager' ? 'Hire a cashier' : 'Add staff');
    document.getElementById('staffFullName').focus();
  }

  function openEdit(s) {
    form.reset();
    document.getElementById('staffId').value = s.id;
    document.getElementById('staffFullName').value = s.fullName || '';
    document.getElementById('staffPassword').value = '';
    const username = document.getElementById('staffUsername');
    username.value = s.username;
    username.readOnly = true;
    document.getElementById('staffEmail').value = s.email || '';
    document.getElementById('usernameHint').textContent = 'Username cannot be changed \u2014 it is the login and marks their history.';
    document.getElementById('passwordLabel').textContent = 'New password (optional)';
    document.getElementById('passwordHint').textContent = 'Leave blank to keep the current password.';
    fillRoles(s.role, s.role === 'owner' || myRole !== 'owner');
    openModal('Edit ' + (s.fullName || ('@' + s.username)));
    document.getElementById('staffFullName').focus();
  }

  /* ---------------- events ---------------- */
  addBtn.addEventListener('click', openCreate);
  document.getElementById('modalClose').addEventListener('click', closeModal);
  document.getElementById('cancelBtn').addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
  document.getElementById('searchInput').addEventListener('input', function (e) {
    searchTerm = e.target.value;
    renderAccounts();
  });

  document.getElementById('tableBody').addEventListener('click', async function (e) {
    const btn = e.target.closest('button[data-act]');
    if (!btn || btn.disabled) return;
    const id = Number(btn.getAttribute('data-id'));
    const s = accounts.find(function (x) { return x.id === id; });
    if (!s) return;
    const act = btn.getAttribute('data-act');
    if (act === 'edit') { openEdit(s); return; }

    if (act === 'unlock') {
      btn.disabled = true;
      try {
        await GMS.staff.unlock(id);
        GMSApp.showToast('Account unlocked \u2014 they can sign in again.');
        await load();
      } catch (err) { GMSApp.showToast(err.message, true); btn.disabled = false; }
      return;
    }

    if (act === 'activate' || act === 'deactivate') {
      const turningOff = act === 'deactivate';
      if (turningOff && !confirm('Deactivate ' + (s.fullName || s.username) + '? They will not be able to sign in, but their old bills keep their name.')) return;
      btn.disabled = true;
      try {
        await GMS.staff.update(id, { fullName: s.fullName || s.username, role: s.role, email: s.email || '', isActive: !turningOff });
        GMSApp.showToast(turningOff ? 'Account deactivated.' : 'Account reactivated \u2014 they can sign in again.');
        await load();
      } catch (err) { GMSApp.showToast(err.message, true); btn.disabled = false; }
      return;
    }

    if (act === 'delete') {
      if (!confirm('Permanently delete ' + (s.fullName || s.username) + '? Only do this for a mistakenly created account \u2014 it cannot be undone.')) return;
      btn.disabled = true;
      try {
        await GMS.staff.remove(id);
        GMSApp.showToast('Account deleted.');
        await load();
      } catch (err) { GMSApp.showToast(err.message, true); btn.disabled = false; }
    }
  });

  document.getElementById('approvalBody').addEventListener('click', async function (e) {
    const btn = e.target.closest('button[data-approve], button[data-reject]');
    if (!btn || btn.disabled) return;
    const approveId = btn.getAttribute('data-approve');
    const rejectId = btn.getAttribute('data-reject');
    const id = Number(approveId || rejectId);
    if (rejectId && !confirm('Reject this password change? The old password keeps working.')) return;
    btn.disabled = true;
    try {
      const res = approveId ? await GMS.account.approve(id) : await GMS.account.reject(id);
      GMSApp.showToast((res && res.message) || 'Done.');
      await load();
    } catch (err) {
      GMSApp.showToast(err.message, true);
      btn.disabled = false;
    }
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const id = document.getElementById('staffId').value;
    const fullName = document.getElementById('staffFullName').value.trim();
    const username = document.getElementById('staffUsername').value.trim();
    const email = document.getElementById('staffEmail').value.trim();
    const password = document.getElementById('staffPassword').value;
    const roleValue = roleSelect.value;

    let err = GMS.validate.name(fullName, 'Full name', 150);
    if (!err && email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
      err = 'Enter a valid email address (or leave it blank).';
    }
    if (!err && !id) {
      if (!/^[A-Za-z0-9._]{3,50}$/.test(username)) {
        err = 'Username must be 3\u201350 characters: letters, digits, dot or underscore.';
      } else if (password.length < 8) {
        err = 'Password must be at least 8 characters.';
      }
    }
    if (!err && id && password !== '' && password.length < 8) {
      err = 'New password must be at least 8 characters.';
    }
    if (err) { GMSApp.showToast(err, true); return; }

    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    try {
      if (id) {
        const payload = { fullName: fullName, role: roleValue, email: email };
        if (password !== '') payload.password = password;
        await GMS.staff.update(Number(id), payload);
        GMSApp.showToast('Account updated.');
      } else {
        await GMS.staff.add({ fullName: fullName, username: username, email: email, password: password, role: roleValue });
        GMSApp.showToast((ROLE_LABEL[roleValue] || 'Staff') + ' account created \u2014 they can sign in now.');
      }
      closeModal();
      await load();
    } catch (err2) {
      GMSApp.showToast(err2.message, true);
    } finally {
      saveBtn.disabled = false;
    }
  });

  await load();
});
