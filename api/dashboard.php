<?php
// api/dashboard.php — summary stats, low-stock/expiry watch, top sellers, recent sales.
// Everything is computed in SQL so the UI shows live database values.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = db();
    // Profit/cost figures: owner/manager only (cashier has its own counter page).
    require_roles(['owner', 'manager'], 'view reports');
    $today = date('Y-m-d');

    $totalSales     = (float)$pdo->query('SELECT COALESCE(SUM(final_amount),0) FROM sales')->fetchColumn();
    $todayRevenue   = (float)$pdo->query('SELECT COALESCE(SUM(final_amount),0) FROM sales WHERE DATE(sale_date) = ' . $pdo->quote($today))->fetchColumn();
    $totalPurchases = (float)$pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM purchases')->fetchColumn();
    $totalExpenses  = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM expenses')->fetchColumn();
    // Cost of Goods Sold: only the cost of products actually sold
    // (sold qty × current purchase price), NOT the whole inventory purchase.
    // Cost of Goods Sold: snapshot cost if present, else current purchase price.
    $cogs = (float)$pdo->query('SELECT COALESCE(SUM(sd.quantity * COALESCE(sd.unit_cost, p.purchase_price)),0) FROM sale_details sd JOIN products p ON p.product_id = sd.product_id')->fetchColumn();
    $grossProfit = $totalSales - $cogs;
    $netProfit = $grossProfit - $totalExpenses;
    $productCount   = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $salesCount     = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $lowStockCount  = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity <= minimum_stock')->fetchColumn();

    $watch = $pdo->query(
        "SELECT p.product_id, p.product_name, p.stock_quantity, p.minimum_stock, p.expiry_date, c.category_name,
                DATEDIFF(p.expiry_date, CURDATE()) AS days_left
         FROM products p LEFT JOIN categories c ON c.category_id = p.category_id
         WHERE p.stock_quantity <= p.minimum_stock
            OR (p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
         ORDER BY p.stock_quantity ASC LIMIT 20"
    )->fetchAll();

    $top = $pdo->query(
        'SELECT p.product_id, p.product_name, c.category_name, SUM(sd.quantity) qty, SUM(sd.subtotal) revenue
         FROM sale_details sd JOIN products p ON p.product_id = sd.product_id
         LEFT JOIN categories c ON c.category_id = p.category_id
         GROUP BY p.product_id ORDER BY qty DESC LIMIT 5'
    )->fetchAll();

    $recent = $pdo->query(
        'SELECT s.sale_id, s.sale_date, s.final_amount, cu.customer_name
         FROM sales s LEFT JOIN customers cu ON cu.customer_id = s.customer_id
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
                    'stock' => (int)$r['stock_quantity'], 'reorderLevel' => (int)$r['minimum_stock'],
                    'expiry' => $r['expiry_date'] ? substr($r['expiry_date'], 0, 10) : '',
                    'daysLeft' => $r['days_left'] !== null ? (int)$r['days_left'] : null];
        }, $watch),
        'top' => array_map(function ($r) {
            return ['id' => (int)$r['product_id'], 'name' => $r['product_name'], 'qty' => (int)$r['qty'], 'revenue' => (float)$r['revenue']];
        }, $top),
        'recent' => array_map(function ($r) {
            return ['id' => (int)$r['sale_id'], 'customer' => $r['customer_name'] ?? 'Walk-in customer',
                    'date' => substr($r['sale_date'], 0, 10), 'total' => (float)$r['final_amount']];
        }, $recent),
    ]]);
} catch (Throwable $e) { fail('Database error: ' . $e->getMessage(), 500); }
