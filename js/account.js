/* account.js — My Account: account facts + password change with approval. */
document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());
  try { await GMS.ready; } catch (e) { GMSApp.showToast('Database error: ' + e.message, true); return; }

  const role = GMS.session.role() || 'owner';
  const ROLE_LABEL = { owner: 'Owner', manager: 'Manager', cashier: 'Cashier' };
  const NEEDS_APPROVAL = { owner: false, manager: true, cashier: true };
  const APPROVER = {
    owner: '',
    manager: 'the Owner',
    cashier: 'the Manager or the Owner',
  };
  let pending = null;

  document.getElementById('accName').textContent = sessionStorage.getItem('gms_name') || '—';
  document.getElementById('accUsername').textContent = '@' + (GMS.session.current() || '—');
  document.getElementById('accRole').textContent = ROLE_LABEL[role] || role;
  document.getElementById('accEmail').textContent = sessionStorage.getItem('gms_email') || 'Not set';

  const notice = document.getElementById('approvalNotice');
  if (NEEDS_APPROVAL[role]) {
    notice.hidden = false;
    notice.innerHTML = 'Approval needed: your new password starts working only after <strong>' +
      APPROVER[role] + '</strong> approves it. Until then your current password keeps working.';
  } else {
    notice.hidden = true;
  }
  document.getElementById('saveBtn').textContent = NEEDS_APPROVAL[role] ? 'Send for approval' : 'Change password';

  function renderPending() {
    const panel = document.getElementById('pendingPanel');
    const text = document.getElementById('pendingText');
    if (!pending) { panel.hidden = true; return; }
    panel.hidden = false;
    const when = pending.requestedAt ? new Date(String(pending.requestedAt).replace(' ', 'T'))
      .toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
    text.textContent = 'A new password you asked for on ' + when +
      (pending.mode === 'forgot' ? ' (via emailed code)' : '') +
      ' is waiting for ' + APPROVER[role] + '. Until it is approved, keep using your current password.';
  }

  async function loadPending() {
    try {
      const data = await GMS.account.list();
      pending = data.mine || null;
      if (data.mine) sessionStorage.setItem('gms_email_checked', '1');
      renderPending();
    } catch (e) { /* the page still works without the list */ }
  }

  document.getElementById('passwordForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const current = document.getElementById('currentPassword').value;
    const next = document.getElementById('newPassword').value;
    const confirm = document.getElementById('confirmPassword').value;

    if (!current) { GMSApp.showToast('Enter your current password.', true); return; }
    if (next.length < 8) { GMSApp.showToast('New password must be at least 8 characters.', true); return; }
    if (next !== confirm) { GMSApp.showToast('The two new passwords do not match.', true); return; }
    if (next === current) { GMSApp.showToast('The new password must be different from the current one.', true); return; }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    try {
      const res = await GMS.account.request({ currentPassword: current, newPassword: next });
      document.getElementById('passwordForm').reset();
      GMSApp.showToast(res.message || 'Saved.');
      await loadPending();
    } catch (err) {
      GMSApp.showToast(err.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('cancelRequestBtn').addEventListener('click', async function () {
    if (!pending) return;
    try {
      const res = await GMS.account.cancel(pending.id);
      GMSApp.showToast(res.message || 'Request cancelled.');
      await loadPending();
    } catch (err) { GMSApp.showToast(err.message, true); }
  });

  loadPending();
});
