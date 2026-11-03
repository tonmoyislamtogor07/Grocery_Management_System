/* account.js — My Account: one profile card, two modals.
   - "Edit profile"      → own email + phone   (api/profile.php, any role)
   - "Change password"   → instant, no approval (api/password.php)
   A temporary password set by an administrator auto-opens the password modal
   and keeps the rest of the site locked until a new one is saved. */
document.addEventListener('DOMContentLoaded', async function () {
  document.getElementById('todayDate').textContent = GMS.formatDate(GMS.todayISO());

  const ROLE_LABEL = { owner: 'Owner', manager: 'Manager', cashier: 'Cashier' };
  const mustChange = GMS.session.mustChange();
  let me = {
    fullName: sessionStorage.getItem('gms_name') || '',
    username: GMS.session.current() || '',
    role: GMS.session.role() || '',
    email: sessionStorage.getItem('gms_email') || '',
    phone: sessionStorage.getItem('gms_phone') || '',
  };

  /* ---------------- modals ---------------- */
  function openModal(id) { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }

  document.querySelectorAll('[data-close]').forEach(function (btn) {
    btn.addEventListener('click', function () { closeModal(btn.getAttribute('data-close')); });
  });
  ['profileModal', 'passwordModal'].forEach(function (id) {
    const el = document.getElementById(id);
    el.addEventListener('click', function (e) {
      // The password modal cannot be dismissed while a temporary password is in force.
      if (e.target === el && !(id === 'passwordModal' && mustChange)) closeModal(id);
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !mustChange) {
      closeModal('profileModal');
      closeModal('passwordModal');
    }
  });

  /* ---------------- render ---------------- */
  function render() {
    const name = me.fullName || me.username || '—';
    document.getElementById('accAvatar').textContent = (name.trim()[0] || 'S').toUpperCase();
    document.getElementById('accName').textContent = name;
    document.getElementById('accUsername').textContent = '@' + (me.username || '—');
    document.getElementById('accRoleTag').textContent = ROLE_LABEL[me.role] || me.role || '—';
    document.getElementById('accRole').textContent = ROLE_LABEL[me.role] || me.role || '—';
    document.getElementById('accEmail').textContent = me.email || 'Not set';
    document.getElementById('accPhone').textContent = me.phone || 'Not set';
    // A person without an email cannot use "Forgot password?" at all.
    document.getElementById('noEmailNotice').hidden = !!me.email;
    try {
      sessionStorage.setItem('gms_name', me.fullName || '');
      sessionStorage.setItem('gms_email', me.email || '');
      sessionStorage.setItem('gms_phone', me.phone || '');
    } catch (e) {}
  }
  render();

  // Fresh row from the server (the session copy can be a page old).
  try {
    me = await GMS.profile.get();
    render();
  } catch (e) {
    if (!GMS.session.current()) return; // signed out — app.js already redirected
  }

  /* ---------------- temporary password ---------------- */
  if (mustChange) {
    document.getElementById('mustChangeNotice').hidden = false;
    document.getElementById('tempPasswordNote').hidden = false;
    // The one action that matters now: make it open on its own, with no way out.
    document.getElementById('passwordModalClose').classList.add('is-hidden');
    document.getElementById('cancelPasswordBtn').classList.add('is-hidden');
    openModal('passwordModal');
    document.getElementById('currentPassword').focus();
  }

  /* ---------------- Edit profile ---------------- */
  document.getElementById('editProfileBtn').addEventListener('click', function () {
    document.getElementById('contactEmail').value = me.email || '';
    document.getElementById('contactPhone').value = me.phone || '';
    openModal('profileModal');
    document.getElementById('contactEmail').focus();
  });

  document.getElementById('contactForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const email = document.getElementById('contactEmail').value.trim();
    const phone = document.getElementById('contactPhone').value.trim();

    let err = GMS.validate.email(email);
    if (!err) err = GMS.validate.phone(phone, 'Phone', false);
    if (err) { GMSApp.showToast(err, true); return; }

    const btn = document.getElementById('contactSaveBtn');
    btn.disabled = true;
    try {
      me = await GMS.profile.save({ email: email, phone: phone });
      render();
      closeModal('profileModal');
      GMSApp.showToast('Contact details saved.');
    } catch (err2) {
      GMSApp.showToast(err2.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  /* ---------------- Change password ---------------- */
  document.getElementById('changePasswordBtn').addEventListener('click', function () {
    openModal('passwordModal');
    document.getElementById('currentPassword').focus();
  });

  document.getElementById('passwordForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const current = document.getElementById('currentPassword').value;
    const next = document.getElementById('newPassword').value;
    const confirm = document.getElementById('confirmPassword').value;

    if (!current) {
      GMSApp.showToast('Enter your current password' + (mustChange ? ' — the temporary one an administrator gave you.' : '.'), true);
      return;
    }
    const weak = GMS.validate.password(next);
    if (weak) { GMSApp.showToast(weak, true); document.getElementById('newPassword').focus(); return; }
    if (next !== confirm) { GMSApp.showToast('The two new passwords do not match.', true); return; }
    if (next === current) { GMSApp.showToast('The new password must be different from the current one.', true); return; }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    try {
      const res = await GMS.account.request({ currentPassword: current, newPassword: next });
      document.getElementById('passwordForm').reset();
      if (window.GMSPassword) window.GMSPassword.refreshAll();
      GMSApp.showToast(res.message || 'Password changed.');
      closeModal('passwordModal');
      if (mustChange) {
        // The temporary password is gone: unlock the rest of the site.
        GMS.session.clearMustChange();
        document.getElementById('mustChangeNotice').hidden = true;
        setTimeout(function () { location.href = GMS.session.home(); }, 1000);
      }
    } catch (err) {
      GMSApp.showToast(err.message, true);
    } finally {
      btn.disabled = false;
    }
  });
});
