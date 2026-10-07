# Sufian Store — Grocery Management System (PHP + MySQL)

Full-stack build for the GMS project (Database Systems, 2026-II, Group 05).
UI: **HTML, CSS, vanilla JavaScript**. Backend: **PHP (PDO) + MySQL**.
No frameworks, no build step. No dummy data — every page reads/writes the database.

## 1. Database setup

1. Start MySQL (XAMPP Control Panel → MySQL → Start, or any MySQL 5.7+/8.x).
2. Create + seed in one go — import [`database.sql`](database.sql):
   - **phpMyAdmin:** create database `gms_db` → Import → choose `database.sql` → Go.
   - **CLI:** `mysql -u root -p -e "CREATE DATABASE gms_db"` then
     `mysql -u root -p gms_db < database.sql`
3. Tables match the ER diagram (`app_user, category, supplier, customer,
   product, product_supplier, purchase, purchase_detail, stock_lot,
   sale, sale_detail, sale_lot, sale_payment, expense`) plus a
   read-only view `v_product_live` (live stock, average cost and earliest
   expiry per product — all derived from `stock_lot`, never stored).
   Stock moves in real batches: each purchase line creates one `stock_lot`
   row (own cost + expiry), each sale consumes oldest-expiry batches first
   (`sale_lot` keeps the exact audit + COGS). Bill totals, paid and due
   are all derived at read time — nothing stored. Seeded logins:
   **Owner** `admin / admin123`, **Manager** `manager / manager123`,
   **Cashier** `cashier / cashier123`.

If MySQL uses a non-default user/password/host, set env vars before
starting PHP: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
(defaults: `127.0.0.1`, `gms_db`, `root`, empty password).

## 2. How to run it

You **must** serve the folder with PHP (the `api/*.php` endpoints need it —
opening `index.html` via `file://` will not work anymore):

```
cd gms-website
php -S localhost:8000
```

then visit `http://localhost:8000`. **Login:** just username + password —
the backend finds the account and detects its role itself
(`admin` → Owner, `manager` → Manager, `cashier` → Cashier;
passwords `admin123` / `manager123` / `cashier123`). Access is role-based:
Owner = all pages, Manager = all except Expenses, Cashier = its own
Cashier Dashboard (today's bills, sales, cash, due + quick actions) +
New Sale + Customers + a limited Reports view (Sales, Top-Selling and
Low-Stock & Expiry tabs only) — never profit, purchase cost, expense,
purchase or supplier figures; typing a restricted page URL bounces you
to your home page. Cost fields are also stripped server-side for the
cashier role (`product.purchasePrice` reads as 0, bill line costs read
as null).
Partial payment is allowed: unpaid amount stays as **due** on the bill.

With XAMPP: copy this folder to `htdocs/gms-website`, start Apache +
MySQL, visit `http://localhost/gms-website`.

## 3. How data works now

- `js/store.js` is an **API client** (previously localStorage dummy data).
  Same `GMS.*` names, but async: `await GMS.ready`, then
  `await GMS.product.all()`, `await GMS.sale.add(...)`, etc.
- Every page (`dashboard, product, category, supplier, customer,
  purchase, sale, expense, reports`) fetches from / writes to MySQL.
- Stock moves **inside MySQL transactions** (real batches, FEFO):
  - `POST api/purchases.php` inserts `purchase` + `purchase_detail` and
    creates one `stock_lot` row per line (own cost + expiry); the supplier
    is auto-linked to each product (`product_supplier`).
  - `POST api/sales.php` validates live batch stock (oversell → HTTP 409),
    inserts `sale` + `sale_detail`, consumes oldest-expiry lots first and
    records the audit in `sale_lot`; the first cash taken is stored as a
    `sale_payment` row (later dues are collected via `api/sale_payments.php`,
    which rejects overpayment).
- Deletes respect foreign keys (e.g. a product in a receipt, or a
  supplier with purchase, cannot be deleted — the API returns 409 and
  the UI shows the message).
- **Validation + security (`config/db.php`):** every endpoint passes
  untrusted input through `v_*` validators before the database —
  names (2+ chars, letters/digits + ` .,'-()&/%+`, tags rejected),
  phones (Bangladesh mobile: exactly 11 digits, `01[3-9]…`),
  emails, money (finite, rounded to 2dp), quantities (whole numbers),
  real calendar dates, positive-integer IDs. Sanitization strips HTML
  tags and control characters; all queries use PDO prepared statements;
  passwords use `password_verify` (bcrypt, 72-char cap); request bodies
  capped at 1 MB. The UI mirrors the same rules instantly via
  `GMS.validate` before sending.
- `GET api/dashboard.php` computes stats, low-stock/expiry watch,
  top sellers and recent sale in SQL. Profit is COGS-based:
  Gross = sale − cost of actually sold goods
  (`sale_detail.qty × product.purchase_price`), Net = gross − expense.
  Unsold stock is never subtracted from profit. Each bill also snapshots
  the purchase price at sale time (`sale_detail.unit_cost`), so later
  cost changes never rewrite profit history.

## Pages

| File | Purpose |
|---|---|
| `index.html` | Login (checks `app_user` table via `api/auth.php`) |
| `cashier.html` | Cashier Dashboard — today's bills/sale/cash/due + quick actions |
| `dashboard.html` | Summary stats, low-stock/expiry watch, top sellers, recent sale |
| `product.html` | Product catalog — add/edit/delete, stock & expiry status |
| `category.html` | Category management |
| `supplier.html` | Supplier contacts & purchase counts |
| `customer.html` | Customer contacts (walk-ins don't need a record) |
| `purchase.html` | Record stock from supplier (auto-increases stock) |
| `sale.html` | POS invoice — receipt preview, printable cash memo (auto-decreases stock) |
| `expense.html` | Rent, electricity, salary, transport, etc. |
| `reports.html` | Sales, Purchases, Profit, Top-Selling, Low-Stock & Expiry, Supplier Summary |

## File structure

```
gms-website/
├── index.html  dashboard.html  product.html  category.html
├── supplier.html  customer.html  purchase.html  sale.html
├── expense.html  reports.html
├── database.sql            (schema + seed data — import into MySQL)
├── config/
│   └── db.php              (PDO connection + row→UI mappers)
├── api/
│   ├── auth.php            (login / me / logout, PHP sessions)
│   ├── categories.php      (CRUD)
│   ├── products.php        (CRUD)
│   ├── suppliers.php       (CRUD)
│   ├── customers.php       (CRUD)
│   ├── expenses.php        (CRUD)
│   ├── purchases.php       (list + create with stock increase)
│   ├── sales.php           (list + create with stock decrease)
│   ├── sale_payments.php   (due collection, overpay rejected)
│   ├── lots.php            (batch view per product)
│   └── dashboard.php       (stats / watch / top / recent)
├── css/
│   └── ...                 (shared design system)
└── js/
    ├── store.js            (API data layer — replaces dummy data)
    ├── app.js              (sidebar nav, auth guard, toasts)
    ├── dashboard.js  product.js  category.js  supplier.js
    ├── customer.js  purchase.js  sale.js  expense.js  reports.js
```

## Design notes

"Digital ledger" look drawn from the shop's paper forms — ruled tables,
receipt-style totals on the sale screen, left accent bars for
stock/expiry status. Deep green + turmeric accent, Space Grotesk
headings + Inter body.
