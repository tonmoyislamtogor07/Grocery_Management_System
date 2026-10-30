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
    // Use the database session's business date so this matches CURDATE() and
    // the local MariaDB timezone (e.g. Asia/Dhaka), not PHP's server timezone.
    $today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();

    $totalSalesCents = (int)$pdo->query(
        'SELECT COALESCE(SUM(sale_total.gross_cents - sale_total.discount_cents),0)
         FROM (
           SELECT s.sale_id,
                  COALESCE(SUM(sd.quantity * CAST(ROUND(sd.unit_price * 100, 0) AS SIGNED)),0) AS gross_cents,
                  CAST(ROUND(s.discount * 100, 0) AS SIGNED) AS discount_cents
           FROM sale s
           LEFT JOIN sale_detail sd ON sd.sale_id = s.sale_id
           GROUP BY s.sale_id, s.discount
         ) sale_total'
    )->fetchColumn();
    $totalSales = $totalSalesCents / 100;
    $todayRevenueQuery = $pdo->prepare(
        'SELECT COALESCE(SUM(sale_total.gross_cents - sale_total.discount_cents),0) / 100
         FROM (
           SELECT s.sale_id, s.sale_date,
                  COALESCE(SUM(sd.quantity * CAST(ROUND(sd.unit_price * 100, 0) AS SIGNED)),0) AS gross_cents,
                  CAST(ROUND(s.discount * 100, 0) AS SIGNED) AS discount_cents
           FROM sale s
           LEFT JOIN sale_detail sd ON sd.sale_id = s.sale_id
           WHERE DATE(s.sale_date) = ?
           GROUP BY s.sale_id, s.sale_date, s.discount
         ) sale_total'
    );
    $todayRevenueQuery->execute([$today]);
    $todayRevenue = (float)$todayRevenueQuery->fetchColumn();
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

    // Allocate each bill discount in integer cents by largest remainder.
    // Do this in PHP to retain compatibility with MySQL 5.7 (no window funcs).
    $topLines = $pdo->query(
        'SELECT sd.sale_detail_id, sd.sale_id, sd.product_id, sd.quantity,
                sd.unit_price, s.discount, p.product_name, c.category_name
         FROM sale_detail sd
         JOIN sale s ON s.sale_id = sd.sale_id
         JOIN product p ON p.product_id = sd.product_id
         LEFT JOIN category c ON c.category_id = p.category_id
         ORDER BY sd.sale_id, sd.sale_detail_id'
    )->fetchAll();
    $topByProduct = [];
    $saleLines = [];
    foreach ($topLines as $line) $saleLines[(int)$line['sale_id']][] = $line;
    foreach ($saleLines as $lines) {
        $grossCents = [];
        $subtotalCents = 0;
        foreach ($lines as $i => $line) {
            $grossCents[$i] = (int)$line['quantity'] * (int)round((float)$line['unit_price'] * 100);
            $subtotalCents += $grossCents[$i];
        }
        $discountCents = min($subtotalCents, (int)round((float)$lines[0]['discount'] * 100));
        $shares = [];
        $allocatedCents = 0;
        foreach ($lines as $i => $line) {
            $exactShare = $subtotalCents > 0 ? $discountCents * $grossCents[$i] / $subtotalCents : 0;
            $baseShare = (int)floor($exactShare);
            $shares[$i] = ['base' => $baseShare, 'remainder' => $exactShare - $baseShare];
            $allocatedCents += $baseShare;
        }
        $order = array_keys($lines);
        usort($order, function ($a, $b) use ($shares, $lines) {
            $byRemainder = $shares[$b]['remainder'] <=> $shares[$a]['remainder'];
            return $byRemainder !== 0 ? $byRemainder : ((int)$lines[$a]['sale_detail_id'] <=> (int)$lines[$b]['sale_detail_id']);
        });
        $extraCents = $discountCents - $allocatedCents;
        foreach ($lines as $i => $line) {
            $productId = (int)$line['product_id'];
            if (!isset($topByProduct[$productId])) {
                $topByProduct[$productId] = ['id' => $productId, 'name' => $line['product_name'],
                    'category' => $line['category_name'], 'qty' => 0, 'revenueCents' => 0];
            }
            $rank = array_search($i, $order, true);
            $discountShare = $shares[$i]['base'] + ($rank !== false && $rank < $extraCents ? 1 : 0);
            $topByProduct[$productId]['qty'] += (int)$line['quantity'];
            $topByProduct[$productId]['revenueCents'] += $grossCents[$i] - $discountShare;
        }
    }
    $top = array_values($topByProduct);
    usort($top, function ($a, $b) { return $b['qty'] <=> $a['qty'] ?: $a['id'] <=> $b['id']; });
    $top = array_slice($top, 0, 5);
    foreach ($top as &$product) {
        $product['revenue'] = round($product['revenueCents'] / 100, 2);
        unset($product['revenueCents']);
    }
    unset($product);

    $recent = $pdo->query(
        'SELECT s.sale_id, s.sale_date, s.discount, cu.customer_name,
                COALESCE((SELECT SUM(d.quantity * CAST(ROUND(d.unit_price * 100, 0) AS SIGNED)) / 100 FROM sale_detail d WHERE d.sale_id = s.sale_id),0) AS gross
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
        'top' => $top,
        'recent' => array_map(function ($r) {
            return ['id' => (int)$r['sale_id'], 'customer' => $r['customer_name'] ?? 'Walk-in customer',
                    'date' => substr($r['sale_date'], 0, 10), 'total' => round((float)$r['gross'] - (float)$r['discount'], 2)];
        }, $recent),
    ]]);
} catch (Throwable $e) { fail('Database error: ' . $e->getMessage(), 500); }
