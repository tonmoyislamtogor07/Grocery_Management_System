<?php
// api/dashboard.php — summary stats, low-stock/expiry watch, top sellers, recent sale.
// Normalized: every figure is computed with SQL joins (no stored totals).
// COGS is exact (sale_lot -> stock_lot); profit never goes stale.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = db();
    // Profit/cost figures: owner/manager only (cashier has its own counter page).
    require_roles(['owner', 'manager'], 'view reports');
    $today = date('Y-m-d');

    $lineTotal = 'COALESCE(SUM(d.quantity * d.unit_price),0)';
    $totalSales  = (float)$pdo->query("SELECT $lineTotal - COALESCE((SELECT SUM(discount) FROM sale),0) FROM sale_detail d")->fetchColumn();
    $todayRevenue = (float)$pdo->query(
        "SELECT $lineTotal - COALESCE((SELECT SUM(discount) FROM sale WHERE DATE(sale_date) = " . $pdo->quote($today) . "),0)
         FROM sale_detail d JOIN sale s ON s.sale_id = d.sale_id WHERE DATE(s.sale_date) = " . $pdo->quote($today)
    )->fetchColumn();
    $totalPurchases = (float)$pdo->query('SELECT COALESCE(SUM(quantity * unit_price),0) FROM purchase_detail')->fetchColumn();
    $totalExpenses  = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM expense')->fetchColumn();
    // Cost of Goods Sold: exact lot costs of what was actually sold.
    $cogs = (float)$pdo->query('SELECT COALESCE(SUM(sl.quantity * l.unit_cost),0) FROM sale_lot sl JOIN stock_lot l ON l.lot_id = sl.lot_id')->fetchColumn();
    $grossProfit = $totalSales - $cogs;
    $netProfit = $grossProfit - $totalExpenses;
    $productCount   = (int)$pdo->query('SELECT COUNT(*) FROM product')->fetchColumn();
    $salesCount     = (int)$pdo->query('SELECT COUNT(*) FROM sale')->fetchColumn();
    $lowStockCount  = (int)$pdo->query('SELECT COUNT(*) FROM product p LEFT JOIN v_product_live v ON v.product_id = p.product_id WHERE COALESCE(v.stock,0) <= p.minimum_stock')->fetchColumn();

    $watch = $pdo->query(
        "SELECT p.product_id, p.product_name, COALESCE(v.stock,0) AS stock, p.minimum_stock, v.expiry AS expiry_date, c.category_name,
                DATEDIFF(v.expiry, CURDATE()) AS days_left
         FROM product p LEFT JOIN v_product_live v ON v.product_id = p.product_id
         LEFT JOIN category c ON c.category_id = p.category_id
         WHERE COALESCE(v.stock,0) <= p.minimum_stock
            OR (v.expiry IS NOT NULL AND v.expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
         ORDER BY stock ASC LIMIT 20"
    )->fetchAll();

    $top = $pdo->query(
        'SELECT p.product_id, p.product_name, c.category_name, SUM(sd.quantity) qty, SUM(sd.quantity * sd.unit_price) revenue
         FROM sale_detail sd JOIN product p ON p.product_id = sd.product_id
         LEFT JOIN category c ON c.category_id = p.category_id
         GROUP BY p.product_id ORDER BY qty DESC LIMIT 5'
    )->fetchAll();

    $recent = $pdo->query(
        'SELECT s.sale_id, s.sale_date, s.discount, cu.customer_name,
                COALESCE((SELECT SUM(d.quantity * d.unit_price) FROM sale_detail d WHERE d.sale_id = s.sale_id),0) AS gross
         FROM sale s LEFT JOIN customer cu ON cu.customer_id = s.customer_id
         ORDER BY s.sale_date DESC, s.sale_id DESC LIMIT 6'
    )->fetchAll();

    send_json(['ok' => true, 'data' => [
        'today' => $today,
        'stats' => [
            'todayRevenue' => $todayRevenue, 'totalSales' => $totalSales,
            'totalPurchases' => $totalPurchases, 'totalExpenses' => $totalExpenses,
            'cogs' => $cogs, 'grossProfit' => $grossProfit, 'netProfit' => $netProfit,
            // legacy key (kept for compatibility) = net profit
            'estimatedProfit' => $netProfit,
            'productCount' => $productCount, 'salesCount' => $salesCount, 'lowStockCount' => $lowStockCount,
        ],
        'watch' => array_map(function ($r) {
            return ['id' => (int)$r['product_id'], 'name' => $r['product_name'], 'category' => $r['category_name'] ?? '—',
                    'stock' => (int)$r['stock'], 'reorderLevel' => (int)$r['minimum_stock'],
                    'expiry' => $r['expiry_date'] ? substr($r['expiry_date'], 0, 10) : '',
                    'daysLeft' => $r['days_left'] !== null ? (int)$r['days_left'] : null];
        }, $watch),
        'top' => array_map(function ($r) {
            return ['id' => (int)$r['product_id'], 'name' => $r['product_name'], 'qty' => (int)$r['qty'], 'revenue' => (float)$r['revenue']];
        }, $top),
        'recent' => array_map(function ($r) {
            return ['id' => (int)$r['sale_id'], 'customer' => $r['customer_name'] ?? 'Walk-in customer',
                    'date' => substr($r['sale_date'], 0, 10), 'total' => round((float)$r['gross'] - (float)$r['discount'], 2)];
        }, $recent),
    ]]);
} catch (Throwable $e) { fail('Database error: ' . $e->getMessage(), 500); }
