<?php
// api/purchases.php — list purchase (with items) + create purchase.
// Normalized: bill total = SUM(lines) at read time; each line creates one
// stock lot (own cost + expiry); no stored totals, no average maintenance
// (average is derived from lots). Supplier auto-links to every product.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

function purchase_with_items(PDO $pdo, int $pid): ?array {
    $st = $pdo->prepare('SELECT * FROM purchase WHERE purchase_id = ?');
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) return null;
    $st = $pdo->prepare('SELECT d.product_id, d.quantity, d.unit_price, l.expiry_date FROM purchase_detail d LEFT JOIN stock_lot l ON l.purchase_detail_id = d.purchase_detail_id WHERE d.purchase_id = ?');
    $st->execute([$pid]);
    $items = [];
    $total = 0.0;
    foreach ($st->fetchAll() as $r) {
        $qty = (int)$r['quantity'];
        $price = (float)$r['unit_price'];
        $total += $qty * $price;
        $items[] = ['productId' => (int)$r['product_id'], 'qty' => $qty, 'price' => $price,
                    'expiry' => $r['expiry_date'] ? substr((string)$r['expiry_date'], 0, 10) : null];
    }
    return [
        'id' => (int)$p['purchase_id'], 'supplierId' => (int)$p['supplier_id'],
        'date' => substr((string)$p['purchase_date'], 0, 10), 'items' => $items,
        'total' => round($total, 2),
    ];
}

try {
    $pdo = db();
    // Purchase costs are hidden from cashier: owner/manager only (all methods).
    require_roles(['owner', 'manager'], 'view purchase');
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $doc = purchase_with_items($pdo, v_id($_GET['id'], 'purchase'));
            if (!$doc) fail('Purchase not found.', 404);
            send_json(['ok' => true, 'data' => $doc]);
        }
        $ids = $pdo->query('SELECT purchase_id FROM purchase ORDER BY purchase_date DESC, purchase_id DESC')->fetchAll(PDO::FETCH_COLUMN);
        $data = [];
        foreach ($ids as $pid) $data[] = purchase_with_items($pdo, (int)$pid);
        send_json(['ok' => true, 'data' => $data]);
    }
    if ($method === 'POST') {
        $in = json_input();
        $supplierId = v_id($in['supplierId'] ?? $in['supplier_id'] ?? 0, 'supplier');
        $date = v_datetime($in['date'] ?? date('Y-m-d'), 'Purchase date');
        $items = $in['items'] ?? [];
        if (!is_array($items) || count($items) === 0) fail('Supplier and at least one item are required.', 422);
        if (count($items) > 200) fail('Too many items in one purchase (max 200).', 422);
        foreach ($items as $it) {
            if (!is_array($it)) fail('Invalid item format.', 422);
            v_id($it['productId'] ?? 0, 'product');
            v_qty($it['qty'] ?? null, 'Quantity');
            v_money($it['price'] ?? null, 'Unit price');
            if (isset($it['expiry']) && $it['expiry'] !== '' && $it['expiry'] !== null) v_date(clean_text($it['expiry']), 'Expiry date');
        }
        $pdo->beginTransaction();
        // referenced rows must exist (clean 422 instead of an FK 500)
        $st = $pdo->prepare('SELECT 1 FROM supplier WHERE supplier_id = ?');
        $st->execute([$supplierId]);
        if (!$st->fetch()) { $pdo->rollBack(); fail('Supplier not found.', 422); }
        $stP = $pdo->prepare('SELECT 1 FROM product WHERE product_id = ?');
        foreach ($items as $it) {
            $stP->execute([v_id($it['productId'] ?? 0, 'product')]);
            if (!$stP->fetch()) { $pdo->rollBack(); fail('Product #' . $it['productId'] . ' does not exist.', 422); }
        }
        // use the validated + normalized values from here on
        $items = array_map(function ($it) {
            $qty = v_qty($it['qty'], 'Quantity');
            $price = v_money($it['price'], 'Unit price');
            $exp = null;
            if (isset($it['expiry']) && $it['expiry'] !== '' && $it['expiry'] !== null) $exp = v_date(clean_text($it['expiry']), 'Expiry date');
            return ['productId' => v_id($it['productId'], 'product'), 'qty' => $qty, 'price' => $price, 'expiry' => $exp];
        }, $items);
        // merge only identical lines (same product + price + expiry): one purchase
        // line = one batch, so different expiries stay separate lots.
        $merged = [];
        foreach ($items as $it) {
            $key = (int)$it['productId'] . '|' . number_format((float)$it['price'], 2, '.', '') . '|' . ($it['expiry'] ?? '');
            if (!isset($merged[$key])) $merged[$key] = $it;
            else $merged[$key]['qty'] += (int)$it['qty'];
        }
        $items = array_values($merged);
        $st = $pdo->prepare('INSERT INTO purchase (supplier_id, purchase_date) VALUES (?, ?)');
        $st->execute([$supplierId, $date]);
        $pid = (int)$pdo->lastInsertId();
        $stD = $pdo->prepare('INSERT INTO purchase_detail (purchase_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
        // Every line is its own batch (cost + expiry preserved).
        $stB = $pdo->prepare('INSERT INTO stock_lot (product_id, purchase_id, purchase_detail_id, qty_bought, qty_left, unit_cost, expiry_date, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        // Option B: remember this supplier as an allowed source (learn on purchase).
        $stLink = $pdo->prepare('INSERT IGNORE INTO product_supplier (product_id, supplier_id) VALUES (?, ?)');
        foreach ($items as $it) {
            $qty = (int)$it['qty'];
            $price = (float)$it['price'];
            $pidInt = (int)$it['productId'];
            $exp = $it['expiry'] ?? null;
            $stD->execute([$pid, $pidInt, $qty, $price]);
            $did = (int)$pdo->lastInsertId();
            $stB->execute([$pidInt, $pid, $did, $qty, $qty, $price, $exp, $date]);
            $stLink->execute([$pidInt, $supplierId]);
        }
        $pdo->commit();
        send_json(['ok' => true, 'data' => purchase_with_items($pdo, $pid)], 201);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
