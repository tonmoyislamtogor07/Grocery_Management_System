<?php
// api/lots.php — batch view for one product (FEFO order).
// Supplier comes from the purchase bill (lots store no copy).
// GET ?product_id=123 -> lots with supplier names, oldest-expiry first.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = db();
    // Batch costs are hidden from cashier: owner/manager only.
    require_roles(['owner', 'manager'], 'view batches');
    $pid = v_id($_GET['product_id'] ?? $_GET['productId'] ?? 0, 'product');
    $st = $pdo->prepare('SELECT 1 FROM product WHERE product_id = ?');
    $st->execute([$pid]);
    if (!$st->fetch()) fail('Product not found.', 404);
    $st = $pdo->prepare(
        'SELECT l.lot_id, l.qty_bought, l.qty_left, l.unit_cost, l.expiry_date, l.received_at,
                l.purchase_id, sup.supplier_id, sup.supplier_name
         FROM stock_lot l
         LEFT JOIN purchase pu ON pu.purchase_id = l.purchase_id
         LEFT JOIN supplier sup ON sup.supplier_id = pu.supplier_id
         WHERE l.product_id = ?
         ORDER BY (l.expiry_date IS NULL), l.expiry_date ASC, l.lot_id ASC'
    );
    $st->execute([$pid]);
    $data = array_map(function ($r) {
        return [
            'id' => (int)$r['lot_id'],
            'bought' => (int)$r['qty_bought'], 'left' => (int)$r['qty_left'],
            'cost' => (float)$r['unit_cost'],
            'expiry' => $r['expiry_date'] ? substr((string)$r['expiry_date'], 0, 10) : '',
            'received' => substr((string)$r['received_at'], 0, 10),
            'purchaseId' => $r['purchase_id'] !== null ? (int)$r['purchase_id'] : null,
            'supplier' => $r['supplier_name'] ?? ($r['supplier_id'] !== null ? ('#' . $r['supplier_id']) : 'Opening'),
        ];
    }, $st->fetchAll());
    send_json(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
