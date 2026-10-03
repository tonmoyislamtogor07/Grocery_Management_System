<?php
// api/sales.php — list sales (with items) + create sale (validates + auto-decreases stock).
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

function sale_with_items(PDO $pdo, int $sid): ?array {
    $st = $pdo->prepare('SELECT * FROM sales WHERE sale_id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) return null;
    $st = $pdo->prepare('SELECT product_id, quantity, unit_price, subtotal, unit_cost FROM sale_details WHERE sale_id = ?');
    $st->execute([$sid]);
    $items = array_map(function ($r) {
        return ['productId' => (int)$r['product_id'], 'qty' => (int)$r['quantity'], 'price' => (float)$r['unit_price'],
                'cost' => $r['unit_cost'] !== null ? (float)$r['unit_cost'] : null];
    }, $st->fetchAll());
    return [
        'id' => (int)$s['sale_id'], 'customerId' => $s['customer_id'] !== null ? (int)$s['customer_id'] : null,
        'date' => substr((string)$s['sale_date'], 0, 10), 'time' => substr((string)$s['sale_date'], 11, 5),
        'items' => $items,
        'total' => (float)$s['final_amount'], 'cashReceived' => (float)$s['cash_received'],
    ];
}

try {
    $pdo = db();
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $doc = sale_with_items($pdo, (int)$_GET['id']);
            if (!$doc) fail('Sale not found.', 404);
            send_json(['ok' => true, 'data' => $doc]);
        }
        $ids = $pdo->query('SELECT sale_id FROM sales ORDER BY sale_date DESC, sale_id DESC')->fetchAll(PDO::FETCH_COLUMN);
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
            $st = $pdo->prepare('SELECT 1 FROM customers WHERE customer_id = ?');
            $st->execute([$customerId]);
            if (!$st->fetch()) { $pdo->rollBack(); fail('Customer not found.', 422); }
        }
        $total = 0.0;
        // validate stock first (lots are consumed oldest-expiry-first at insert time)
        $stStock = $pdo->prepare('SELECT stock_quantity FROM products WHERE product_id = ?');
        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it)) { $pdo->rollBack(); fail('Invalid item format.', 422); }
            $pid = v_id($it['productId'] ?? 0, 'product');
            $qty = v_qty($it['qty'] ?? null, 'Quantity');
            $price = v_money($it['price'] ?? null, 'Unit price');
            $stStock->execute([$pid]);
            $row = $stStock->fetch();
            if (!$row) { $pdo->rollBack(); fail('Product #' . $pid . ' does not exist.', 422); }
            if ($qty > (int)$row['stock_quantity']) { $pdo->rollBack(); fail('Not enough stock for product #' . $pid . ' (have ' . $row['stock_quantity'] . ').', 409); }
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
        // (due = final_amount - cash_received, shown on the cashier board).
        $st = $pdo->prepare('INSERT INTO sales (customer_id, total_amount, discount, final_amount, cash_received, sale_date) VALUES (?, ?, 0, ?, ?, ?)');
        $st->execute([$customerId, $total, $total, $cash, $date]);
        $sid = (int)$pdo->lastInsertId();
        $stD = $pdo->prepare('INSERT INTO sale_details (sale_id, product_id, quantity, unit_price, subtotal, unit_cost) VALUES (?, ?, ?, ?, ?, ?)');
        $stA = $pdo->prepare('INSERT INTO sale_lots (sale_detail_id, lot_id, quantity) VALUES (?, ?, ?)');
        $stS = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ?');
        $stL = $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_changed) VALUES (?, 'sale', ?)");
        $touched = [];
        foreach ($items as $it) {
            $qty = (int)$it['qty'];
            $price = (float)$it['price'];
            $pidInt = (int)$it['productId'];
            // Real COGS: consume oldest-expiry batches first; the line cost is the
            // weighted average of the batches actually used (exact, survives later
            // cost changes via the snapshot + sale_lots audit rows).
            $used = consume_fifo($pdo, $pidInt, $qty);
            $lineCost = $qty > 0 ? round($used['cost'] / $qty, 2) : 0.0;
            $stD->execute([$sid, $pidInt, $qty, $price, $qty * $price, $lineCost]);
            $did = (int)$pdo->lastInsertId();
            foreach ($used['alloc'] as $lotId => $take) $stA->execute([$did, $lotId, $take]);
            $stS->execute([$qty, $pidInt]);
            $stL->execute([$pidInt, -$qty]);
            $touched[$pidInt] = true;
        }
        foreach (array_keys($touched) as $pidInt) refresh_product_expiry($pdo, $pidInt);
        $pdo->commit();
        send_json(['ok' => true, 'data' => sale_with_items($pdo, $sid)], 201);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
