<?php
// api/purchases.php — list purchases (with items) + create purchase (auto-increases stock).
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

function purchase_with_items(PDO $pdo, int $pid): ?array {
    $st = $pdo->prepare('SELECT * FROM purchases WHERE purchase_id = ?');
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) return null;
    $st = $pdo->prepare('SELECT product_id, quantity, unit_price, subtotal FROM purchase_details WHERE purchase_id = ?');
    $st->execute([$pid]);
    $items = array_map(function ($r) {
        return ['productId' => (int)$r['product_id'], 'qty' => (int)$r['quantity'], 'price' => (float)$r['unit_price']];
    }, $st->fetchAll());
    return [
        'id' => (int)$p['purchase_id'], 'supplierId' => (int)$p['supplier_id'],
        'date' => substr((string)$p['purchase_date'], 0, 10), 'items' => $items,
        'total' => (float)$p['total_amount'],
    ];
}

try {
    $pdo = db();
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $doc = purchase_with_items($pdo, v_id($_GET['id'], 'purchase'));
            if (!$doc) fail('Purchase not found.', 404);
            send_json(['ok' => true, 'data' => $doc]);
        }
        $ids = $pdo->query('SELECT purchase_id FROM purchases ORDER BY purchase_date DESC, purchase_id DESC')->fetchAll(PDO::FETCH_COLUMN);
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
        }
        $pdo->beginTransaction();
        // referenced rows must exist (clean 422 instead of an FK 500)
        $st = $pdo->prepare('SELECT 1 FROM suppliers WHERE supplier_id = ?');
        $st->execute([$supplierId]);
        if (!$st->fetch()) { $pdo->rollBack(); fail('Supplier not found.', 422); }
        $stP = $pdo->prepare('SELECT 1 FROM products WHERE product_id = ?');
        foreach ($items as $it) {
            $stP->execute([v_id($it['productId'] ?? 0, 'product')]);
            if (!$stP->fetch()) { $pdo->rollBack(); fail('Product #' . $it['productId'] . ' does not exist.', 422); }
        }
        $total = 0.0;
        // use the validated + normalized values from here on
        $items = array_map(function ($it) {
            $qty = v_qty($it['qty'], 'Quantity');
            $price = v_money($it['price'], 'Unit price');
            return ['productId' => v_id($it['productId'], 'product'), 'qty' => $qty, 'price' => $price];
        }, $items);
        foreach ($items as $it) $total += $it['qty'] * $it['price'];
        $st = $pdo->prepare('INSERT INTO purchases (supplier_id, total_amount, purchase_date) VALUES (?, ?, ?)');
        $st->execute([$supplierId, $total, $date]);
        $pid = (int)$pdo->lastInsertId();
        $stD = $pdo->prepare('INSERT INTO purchase_details (purchase_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)');
        $stS = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?');
        $stL = $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_changed) VALUES (?, 'purchase', ?)");
        foreach ($items as $it) {
            $qty = (int)$it['qty'];
            $price = (float)$it['price'];
            $stD->execute([$pid, (int)$it['productId'], $qty, $price, $qty * $price]);
            $stS->execute([$qty, (int)$it['productId']]);
            $stL->execute([(int)$it['productId'], $qty]);
        }
        $pdo->commit();
        send_json(['ok' => true, 'data' => purchase_with_items($pdo, $pid)], 201);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
