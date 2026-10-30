<?php
// api/sales.php — list sale (with items) + create sale.
// Normalized: bill total/final/paid/due are all derived at read time
// (lines, discount, sale_payment). The first cash IS a payment row.
// Stock moves only through stock_lot (FEFO consume); no stored columns.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

function sale_sums(PDO $pdo, int $sid): array {
    $st = $pdo->prepare('SELECT COALESCE(SUM(quantity * CAST(ROUND(unit_price * 100, 0) AS SIGNED)),0) FROM sale_detail WHERE sale_id = ?');
    $st->execute([$sid]);
    $totalCents = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT CAST(ROUND(discount * 100, 0) AS SIGNED) FROM sale WHERE sale_id = ?');
    $st->execute([$sid]);
    $discountCents = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COALESCE(SUM(CAST(ROUND(amount * 100, 0) AS SIGNED)),0) FROM sale_payment WHERE sale_id = ?');
    $st->execute([$sid]);
    $paidCents = (int)$st->fetchColumn();
    $finalCents = $totalCents - $discountCents;
    return ['total' => $totalCents / 100, 'discount' => $discountCents / 100,
            'final' => $finalCents / 100, 'paid' => $paidCents / 100,
            'due' => max(0, $finalCents - $paidCents) / 100];
}

function sale_with_items(PDO $pdo, int $sid): ?array {
    $st = $pdo->prepare(
        "SELECT s.*, COALESCE(NULLIF(u.full_name, ''), u.username) AS recorded_by,
                u.username AS recorded_username, u.role AS recorded_role
         FROM sale s LEFT JOIN app_user u ON u.user_id = s.user_id
         WHERE s.sale_id = ?"
    );
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) return null;
    // Per-line exact cost from the lots actually consumed (kept for the UI).
    $st = $pdo->prepare(
        'SELECT d.product_id, d.quantity, d.unit_price,
                COALESCE(SUM(sl.quantity * l.unit_cost), 0) AS line_cost_total,
                CASE WHEN d.quantity > 0
                  THEN ROUND(COALESCE(SUM(sl.quantity * l.unit_cost), 0) / d.quantity, 2)
                  ELSE 0 END AS line_cost
         FROM sale_detail d
         LEFT JOIN sale_lot sl ON sl.sale_detail_id = d.sale_detail_id
         LEFT JOIN stock_lot l ON l.lot_id = sl.lot_id
         WHERE d.sale_id = ?
         GROUP BY d.sale_detail_id'
    );
    $st->execute([$sid]);
    $items = array_map(function ($r) {
        // Cashiers never see exact lot costs (counter staff pricing only).
        $hideCost = (current_role() === 'cashier');
        return ['productId' => (int)$r['product_id'], 'qty' => (int)$r['quantity'], 'price' => (float)$r['unit_price'],
                'cost' => $hideCost ? null : (float)$r['line_cost'],
                'costTotal' => $hideCost ? null : (float)$r['line_cost_total']];
    }, $st->fetchAll());
    $sums = sale_sums($pdo, $sid);
    $st = $pdo->prepare('SELECT amount, paid_at FROM sale_payment WHERE sale_id = ? ORDER BY paid_at ASC, payment_id ASC');
    $st->execute([$sid]);
    $payments = array_map(function ($payment) {
        return ['amount' => (float)$payment['amount'], 'date' => substr((string)$payment['paid_at'], 0, 10),
                'time' => substr((string)$payment['paid_at'], 11, 5)];
    }, $st->fetchAll());
    return [
        'id' => (int)$s['sale_id'], 'customerId' => $s['customer_id'] !== null ? (int)$s['customer_id'] : null,
        'recordedBy' => (string)($s['recorded_by'] ?? ''),
        'recordedUsername' => (string)($s['recorded_username'] ?? ''),
        'recordedRole' => (string)($s['recorded_role'] ?? ''),
        'date' => substr((string)$s['sale_date'], 0, 10), 'time' => substr((string)$s['sale_date'], 11, 5),
        'items' => $items, 'discount' => (float)$sums['discount'], 'payments' => $payments,
        'total' => $sums['final'], 'cashReceived' => $sums['paid'], 'due' => $sums['due'],
    ];
}

try {
    $pdo = db();
    // Billing endpoint: every logged-in role may read + create bills.
    $role = require_login();
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $saleId = v_id($_GET['id'], 'sale');
            $doc = sale_with_items($pdo, $saleId);
            if (!$doc) fail('Sale not found.', 404);
            send_json(['ok' => true, 'data' => $doc]);
        }
        // Every role sees every bill: the counter must be able to look up a
        // bill (whoever made it) to collect its due. Costs and profit stay
        // hidden from the cashier in sale_with_items().
        $ids = $pdo->query('SELECT sale_id FROM sale ORDER BY sale_date DESC, sale_id DESC')->fetchAll(PDO::FETCH_COLUMN);
        $data = [];
        foreach ($ids as $sid) $data[] = sale_with_items($pdo, (int)$sid);
        send_json(['ok' => true, 'data' => $data]);
    }
    if ($method === 'POST') {
        $in = json_input();
        $customerId = $in['customerId'] ?? null;
        if ($customerId === '' || $customerId === 0 || $customerId === null) {
            $customerId = null;
        } else {
            $customerId = v_id($customerId, 'customer');
        }
        $date = v_datetime($in['date'] ?? business_today($pdo), 'Sale date');
        $discount = v_money($in['discount'] ?? 0, 'Discount');
        $cash = v_money($in['cashReceived'] ?? 0, 'Cash received', 0, true, 9999999999.99);
        $items = $in['items'] ?? [];
        if (!is_array($items) || count($items) === 0) fail('At least one item is required.', 422);
        if (count($items) > 200) fail('Too many items in one bill (max 200).', 422);
        $pdo->beginTransaction();
        if ($customerId !== null) {
            $st = $pdo->prepare('SELECT 1 FROM customer WHERE customer_id = ?');
            $st->execute([$customerId]);
            if (!$st->fetch()) { $pdo->rollBack(); fail('Customer not found.', 422); }
        }
        $grossCents = 0;
        // validate against live lots (consumed oldest-expiry-first below)
        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it)) { $pdo->rollBack(); fail('Invalid item format.', 422); }
            $pid = v_id($it['productId'] ?? 0, 'product');
            $qty = v_qty($it['qty'] ?? null, 'Quantity');
            $price = v_money($it['price'] ?? null, 'Unit price');
            $have = live_stock($pdo, $pid);
            $st = $pdo->prepare('SELECT 1 FROM product WHERE product_id = ?');
            $st->execute([$pid]);
            if (!$st->fetch()) { $pdo->rollBack(); fail('Product #' . $pid . ' does not exist.', 422); }
            if ($qty > $have) { $pdo->rollBack(); fail('Not enough stock for product #' . $pid . ' (have ' . $have . ').', 409); }
            $clean[] = ['productId' => $pid, 'qty' => $qty, 'price' => $price];
            $grossCents += (int)round($price * 100) * $qty;
            if ($grossCents > 999999999999) {
                $pdo->rollBack();
                fail('Sale subtotal exceeds the supported limit of 9,999,999,999.99.', 422);
            }
        }
        $items = $clean;
        // The schema stores one unit price per product per bill.
        // Merge identical-price duplicate rows; reject mixed prices so the
        // persisted total cannot silently diverge from the submitted bill.
        $merged = [];
        foreach ($items as $it) {
            $pid = (int)$it['productId'];
            if (!isset($merged[$pid])) $merged[$pid] = $it;
            elseif (abs((float)$merged[$pid]['price'] - (float)$it['price']) > 0.009) {
                $pdo->rollBack();
                fail('A product cannot have different unit prices on the same bill. Combine the quantity at one price.', 422);
            } else {
                $merged[$pid]['qty'] += (int)$it['qty'];
            }
        }
        $items = array_values($merged);
        $totalCents = $grossCents;
        $discountCents = (int)round($discount * 100);
        $total = $totalCents / 100;
        if ($discountCents > $totalCents) {
            $pdo->rollBack();
            fail('Discount cannot exceed the sale subtotal of ' . number_format($total, 2) . '.', 422);
        }
        $finalTotal = ($totalCents - $discountCents) / 100;
        // Partial payment is allowed; cash tender can exceed final total,
        // but only the amount due is recorded as a payment (the rest is change).
        $st = $pdo->prepare('INSERT INTO sale (customer_id, user_id, discount, sale_date) VALUES (?, ?, ?, ?)');
        $st->execute([$customerId, current_user_id(), $discount, $date]);
        $sid = (int)$pdo->lastInsertId();
        $stD = $pdo->prepare('INSERT INTO sale_detail (sale_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
        $stA = $pdo->prepare('INSERT INTO sale_lot (sale_detail_id, lot_id, quantity) VALUES (?, ?, ?)');
        foreach ($items as $it) {
            $qty = (int)$it['qty'];
            $price = (float)$it['price'];
            $pidInt = (int)$it['productId'];
            // Real COGS: consume oldest-expiry batches first (audit in sale_lot).
            $used = consume_fifo($pdo, $pidInt, $qty);
            $stD->execute([$sid, $pidInt, $qty, $price]);
            $did = (int)$pdo->lastInsertId();
            foreach ($used['alloc'] as $lotId => $take) $stA->execute([$did, $lotId, $take]);
        }
        $initialPayment = min($cash, $finalTotal);
        if ($initialPayment > 0) {
            // First cash IS a payment row (paid = SUM(payments)).
            // Payment records the bill amount after change, not the tendered cash.
            $st = $pdo->prepare('INSERT INTO sale_payment (sale_id, amount, paid_at) VALUES (?, ?, ?)');
            $st->execute([$sid, $initialPayment, $date]);
        }
        $pdo->commit();
        send_json(['ok' => true, 'data' => sale_with_items($pdo, $sid)], 201);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
