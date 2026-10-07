<?php
// api/sales.php — list sale (with items) + create sale.
// Normalized: bill total/final/paid/due are all derived at read time
// (lines, discount, sale_payment). The first cash IS a payment row.
// Stock moves only through stock_lot (FEFO consume); no stored columns.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

function sale_sums(PDO $pdo, int $sid): array {
    $st = $pdo->prepare('SELECT COALESCE(SUM(quantity * unit_price),0) FROM sale_detail WHERE sale_id = ?');
    $st->execute([$sid]);
    $total = round((float)$st->fetchColumn(), 2);
    $st = $pdo->prepare('SELECT discount FROM sale WHERE sale_id = ?');
    $st->execute([$sid]);
    $discount = (float)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM sale_payment WHERE sale_id = ?');
    $st->execute([$sid]);
    $paid = round((float)$st->fetchColumn(), 2);
    $final = round($total - $discount, 2);
    return ['total' => $total, 'discount' => $discount, 'final' => $final,
            'paid' => $paid, 'due' => max(0, round($final - $paid, 2))];
}

function sale_with_items(PDO $pdo, int $sid): ?array {
    $st = $pdo->prepare('SELECT * FROM sale WHERE sale_id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) return null;
    // Per-line exact cost from the lots actually consumed (kept for the UI).
    $st = $pdo->prepare(
        'SELECT d.product_id, d.quantity, d.unit_price,
                CASE WHEN d.quantity > 0
                  THEN ROUND(SUM(sl.quantity * l.unit_cost) / d.quantity, 2)
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
                'cost' => $hideCost ? null : ($r['line_cost'] !== null ? (float)$r['line_cost'] : null)];
    }, $st->fetchAll());
    $sums = sale_sums($pdo, $sid);
    return [
        'id' => (int)$s['sale_id'], 'customerId' => $s['customer_id'] !== null ? (int)$s['customer_id'] : null,
        'date' => substr((string)$s['sale_date'], 0, 10), 'time' => substr((string)$s['sale_date'], 11, 5),
        'items' => $items,
        'total' => $sums['final'], 'cashReceived' => $sums['paid'], 'due' => $sums['due'],
    ];
}

try {
    $pdo = db();
    // Billing endpoint: every logged-in role may read + create bills.
    $role = require_login();
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $doc = sale_with_items($pdo, (int)$_GET['id']);
            if (!$doc) fail('Sale not found.', 404);
            send_json(['ok' => true, 'data' => $doc]);
        }
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
        $date = v_datetime($in['date'] ?? date('Y-m-d'), 'Sale date');
        $cash = v_money($in['cashReceived'] ?? 0, 'Cash received');
        $items = $in['items'] ?? [];
        if (!is_array($items) || count($items) === 0) fail('At least one item is required.', 422);
        if (count($items) > 200) fail('Too many items in one bill (max 200).', 422);
        $pdo->beginTransaction();
        if ($customerId !== null) {
            $st = $pdo->prepare('SELECT 1 FROM customer WHERE customer_id = ?');
            $st->execute([$customerId]);
            if (!$st->fetch()) { $pdo->rollBack(); fail('Customer not found.', 422); }
        }
        $total = 0.0;
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
            $total += $qty * $price;
        }
        $items = $clean;
        // merge duplicate product lines so UNIQUE(sale_id, product_id) never breaks
        $merged = [];
        foreach ($items as $it) {
            $pid = (int)$it['productId'];
            if (!isset($merged[$pid])) $merged[$pid] = $it;
            else { $merged[$pid]['qty'] += (int)$it['qty']; $merged[$pid]['price'] = (float)$it['price']; }
        }
        $items = array_values($merged);
        // Partial payment is allowed: whatever is unpaid stays as due
        // (due = final - paid, shown on the cashier board).
        $st = $pdo->prepare('INSERT INTO sale (customer_id, discount, sale_date) VALUES (?, 0, ?)');
        $st->execute([$customerId, $date]);
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
        if ($cash > 0) {
            // First cash IS a payment row (paid = SUM(payments)).
            $st = $pdo->prepare('INSERT INTO sale_payment (sale_id, amount, paid_at) VALUES (?, ?, ?)');
            $st->execute([$sid, $cash, $date]);
        }
        $pdo->commit();
        send_json(['ok' => true, 'data' => sale_with_items($pdo, $sid)], 201);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
