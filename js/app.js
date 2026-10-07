/* ==========================================================================
   app.js — shared shell for every protected page: sidebar nav, mobile
   toggle, auth guard, and a small toast helper. Include after store.js.
   ========================================================================== */

(function () {
  // Role matrix — Owner sees everything, Manager everything except
  // Expenses (owner-only payouts). Cashier gets a separate Cashier
  // Dashboard (today's counter only) + billing — never profit, purchase
  // cost, expenses or sensitive reports.
  const ROLE_PAGES = {
    owner:   ['dashboard', 'cashier', 'products', 'categories', 'purchases', 'sales', 'suppliers', 'customers', 'expenses', 'reports'],
    manager: ['dashboard', 'cashier', 'products', 'categories', 'purchases', 'sales', 'suppliers', 'customers', 'reports'],
    cashier: ['cashier', 'sales', 'customers', 'reports'],
  };

  const NAV_ITEMS = [
    { page: 'dashboard', href: 'dashboard.html', label: 'Dashboard', icon: 'grid', roles: ['owner', 'manager'] },
    { page: 'cashier', href: 'cashier.html', label: 'Dashboard', icon: 'grid', roles: ['cashier'] },
    { page: 'products', href: 'products.html', label: 'Products', icon: 'box', roles: ['owner', 'manager'] },
    { page: 'categories', href: 'categories.html', label: 'Categories', icon: 'tag', roles: ['owner', 'manager'] },
    { page: 'purchases', href: 'purchases.html', label: 'Purchases', icon: 'truck', roles: ['owner', 'manager'] },
    { page: 'sales', href: 'sales.html', label: 'New Sale', icon: 'cart', roles: ['owner', 'manager', 'cashier'] },
    { page: 'suppliers', href: 'suppliers.html', label: 'Suppliers', icon: 'building', roles: ['owner', 'manager'] },
    { page: 'customers', href: 'customers.html', label: 'Customers', icon: 'user', roles: ['owner', 'manager', 'cashier'] },
    { page: 'expenses', href: 'expenses.html', label: 'Expenses', icon: 'wallet', roles: ['owner'] },
    { page: 'reports', href: 'reports.html', label: 'Reports', icon: 'chart', roles: ['owner', 'manager', 'cashier'] }
  ];

  function allowedPages(role) {
    return ROLE_PAGES[role] || ROLE_PAGES.owner;
  }

  function pageAllowed(page, role) {
    return allowedPages(role).indexOf(page) !== -1;
  }

  const ICONS = {
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    box: '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
    tag: '<path d="M20 12l-8 8-9-9V3h8l9 9z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
    truck: '<rect x="1" y="6" width="13" height="10" rx="1"/><path d="M14 9h4l3 3v4h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
    cart: '<circle cx="9" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/><path d="M2 3h2l2.4 12.2a2 2 0 002 1.8h8.2a2 2 0 002-1.7L21 7H6"/>',
    building: '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 8h1M14 8h1M9 12h1M14 12h1M9 16h1M14 16h1"/>',
    user: '<circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-3.6 3.1-6.5 7-6.5s7 2.9 7 6.5"/>',
    wallet: '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="17" cy="15" r="1.3"/>',
    chart: '<path d="M4 19V9"/><path d="M11 19V4"/><path d="M18 19v-7"/><path d="M2 19h20"/>'
  };

  function iconSvg(name) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || '') + '</svg>';
  }

  function renderShell() {
    const root = document.getElementById('app-root');
    if (!root) return;
    const currentPage = document.body.getAttribute('data-page');
    const role = (typeof GMS !== 'undefined' && GMS.session.role()) || 'owner';
    const allowed = allowedPages(role);

    const navHtml = NAV_ITEMS.filter(function (item) {
      return allowed.indexOf(item.page) !== -1 && (!item.roles || item.roles.indexOf(role) !== -1);
    }).map(function (item) {
      const active = item.page === currentPage ? ' active' : '';
      return '<a class="' + active.trim() + '" href="' + item.href + '">' + iconSvg(item.icon) + '<span>' + item.label + '</span></a>';
    }).join('');

    root.insertAdjacentHTML('afterbegin',
      '<button class="nav-toggle" id="navToggle" aria-label="Toggle menu">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg> Menu' +
      '</button>' +
      '<aside class="sidebar" id="sidebar">' +
        '<div class="sidebar-brand"><span class="mark">Sufian Store</span><span class="sub">Grocery Management System</span></div>' +
        '<nav class="sidebar-nav">' + navHtml + '</nav>' +
        '<div class="sidebar-foot">' +
          '<div class="sidebar-user">Signed in as<strong id="sidebarUserName">Admin</strong><span class="sidebar-role" id="sidebarRole"></span></div>' +
          '<button class="logout-btn" id="logoutBtn">Log out</button>' +
        '</div>' +
      '</aside>'
    );

    document.getElementById('navToggle').addEventListener('click', function () {
      document.getElementById('sidebar').classList.toggle('open');
    });

    document.getElementById('logoutBtn').addEventListener('click', function () {
      GMS.session.logout();
    });

    const user = GMS.session.current();
    const nameEl = document.getElementById('sidebarUserName');
    if (nameEl) nameEl.textContent = user || 'Admin';
    const roleEl = document.getElementById('sidebarRole');
    if (roleEl) roleEl.textContent = GMS.session.roleLabel() || '';
  }

  function showToast(message, isError) {
    let el = document.getElementById('gmsToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'gmsToast';
      el.className = 'toast';
      document.body.appendChild(el);
    }
    el.textContent = message;
    el.className = 'toast show' + (isError ? ' toast-danger' : '');
    clearTimeout(el._timer);
    el._timer = setTimeout(function () { el.classList.remove('show'); }, 2600);
  }

  window.GMSApp = { showToast: showToast };

  document.addEventListener('DOMContentLoaded', function () {
    if (document.body.getAttribute('data-page') !== 'login') {
      // Wait for the verified PHP session first, then enforce role access:
      // users who type a restricted page URL are bounced to their home page.
      GMS.session.requireLogin().then(function () {
        const page = document.body.getAttribute('data-page');
        const role = GMS.session.role() || 'owner';
        if (!pageAllowed(page, role)) {
          location.href = GMS.session.home();
          return;
        }
        renderShell();
      }).catch(function () {});
      // Wait for live DB data before pages render their tables.
      GMS.ready.catch(function (e) {
        showToast('Could not reach the database API: ' + e.message, true);
      });
    }
  });

  window.GMSAuth = { allowedPages: allowedPages, pageAllowed: pageAllowed };
})();
