<?php
// api/products.php — CRUD for product (normalized: stock/avg-cost/expiry
// are derived from stock_lot via v_product_live, never stored here).
// POST stock/expiry/supplierIds build the OPENING lot. PUT stock sets the
// desired level: top-ups create a lot at the given cost, write-offs
// consume oldest lots first. PUT expiry stamps new top-up lots only.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

function product_row(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT p.*, v.stock, v.avg_cost, v.expiry FROM product p LEFT JOIN v_product_live v ON v.product_id = p.product_id WHERE p.product_id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    return with_suppliers($pdo, $r);
}

function with_suppliers(PDO $pdo, array $r): array {
    return map_product($r, product_supplier_ids($pdo, (int)$r['product_id']));
}

function check_suppliers(PDO $pdo, array $supplierIds): array {
    $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));
    if (count($supplierIds) > 0) {
        $stS = $pdo->prepare('SELECT 1 FROM supplier WHERE supplier_id = ?');
        foreach ($supplierIds as $sid) {
            $stS->execute([$sid]);
            if (!$stS->fetch()) fail('Supplier #' . $sid . ' does not exist.', 422);
        }
    }
    return $supplierIds;
}

try {
    $pdo = db();
    $role = require_login();
    // Cashier: read-only here (no product writes).
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) require_roles(['owner', 'manager'], 'manage product');
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $r = product_row($pdo, (int)$_GET['id']);
            if (!$r) fail('Product not found.', 404);
            // Cashiers never see purchase cost (counter staff pricing only).
            if ($role === 'cashier') $r['purchasePrice'] = 0.0;
            send_json(['ok' => true, 'data' => $r]);
        }
        $rows = $pdo->query('SELECT p.*, v.stock, v.avg_cost, v.expiry FROM product p LEFT JOIN v_product_live v ON v.product_id = p.product_id ORDER BY product_name')->fetchAll();
        $supMap = product_suppliers_map($pdo, array_column($rows, 'product_id'));
        send_json(['ok' => true, 'data' => array_map(function ($r) use ($supMap, $role) {
            $m = map_product($r, $supMap[(int)$r['product_id']] ?? []);
            if ($role === 'cashier') $m['purchasePrice'] = 0.0;
            return $m;
        }, $rows)]);
    }
    if ($method === 'POST') {
        $in = json_input();
        $name = v_name($in['name'] ?? '', 'Product name', 2, 200);
        $categoryId = v_id($in['categoryId'] ?? 0, 'category');
        $price = v_money($in['price'] ?? null, 'Selling price');
        $stock = v_qty($in['stock'] ?? null, 'Stock', 0);
        $reorder = v_qty($in['reorderLevel'] ?? 5, 'Reorder level', 0);
        $expiry = v_optional_date($in['expiry'] ?? '', 'Expiry date');
        $cost = isset($in['purchasePrice']) && $in['purchasePrice'] !== '' ? v_money($in['purchasePrice'], 'Purchase price') : $price;
        // Option B: multiple allowed supplier (optional). Accepts supplierIds[]; single supplierId kept for backward compat.
        $supplierIds = [];
        if (isset($in['supplierIds']) && is_array($in['supplierIds'])) {
            foreach ($in['supplierIds'] as $sid) $supplierIds[] = v_id($sid, 'supplier');
        } elseif (isset($in['supplierId']) && $in['supplierId'] !== '' && $in['supplierId'] !== null) {
            $supplierIds[] = v_id($in['supplierId'], 'supplier');
        }
        $supplierIds = check_suppliers($pdo, $supplierIds);
        $st = $pdo->prepare('SELECT 1 FROM category WHERE category_id = ?');
        $st->execute([$categoryId]);
        if (!$st->fetch()) fail('Category not found.', 422);
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO product (product_name, category_id, selling_price, minimum_stock) VALUES (?, ?, ?, ?)');
        $st->execute([$name, $categoryId, $price, $reorder]);
        $id = (int)$pdo->lastInsertId();
        if (count($supplierIds) > 0) {
            $stL = $pdo->prepare('INSERT INTO product_supplier (product_id, supplier_id) VALUES (?, ?)');
            foreach ($supplierIds as $sid) $stL->execute([$id, $sid]);
        }
        if ($stock > 0) {
            // Opening batch: no bill/supplier, cost = given price.
            $st = $pdo->prepare('INSERT INTO stock_lot (product_id, purchase_id, qty_bought, qty_left, unit_cost, expiry_date, received_at) VALUES (?, NULL, ?, ?, ?, ?, NOW())');
            $st->execute([$id, $stock, $stock, $cost, $expiry]);
        }
        $pdo->commit();
        send_json(['ok' => true, 'data' => product_row($pdo, $id)], 201);
    }
    if ($method === 'PUT') {
        $id = v_id($_GET['id'] ?? 0, 'product');
        $in = json_input();
        $st = $pdo->prepare('SELECT * FROM product WHERE product_id = ?');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) fail('Product not found.', 404);
        $name = array_key_exists('name', $in) ? v_name($in['name'], 'Product name', 2, 200) : $old['product_name'];
        $categoryId = array_key_exists('categoryId', $in) ? v_id($in['categoryId'], 'category') : $old['category_id'];
        $price = array_key_exists('price', $in) ? v_money($in['price'], 'Selling price') : (float)$old['selling_price'];
        $reorder = array_key_exists('reorderLevel', $in) ? v_qty($in['reorderLevel'], 'Reorder level', 0) : (int)$old['minimum_stock'];
        $wantStock = array_key_exists('stock', $in) ? v_qty($in['stock'], 'Stock', 0) : null;
        $enteredCost = array_key_exists('purchasePrice', $in) && $in['purchasePrice'] !== '' ? v_money($in['purchasePrice'], 'Purchase price') : null;
        $expiry = array_key_exists('expiry', $in) ? v_optional_date($in['expiry'], 'Expiry date') : null;
        // Option B: replace allowed-supplier links only when the key is sent.
        $touchSuppliers = array_key_exists('supplierIds', $in) || array_key_exists('supplierId', $in);
        $supplierIds = product_supplier_ids($pdo, $id);
        if (array_key_exists('supplierIds', $in)) {
            $supplierIds = [];
            if (is_array($in['supplierIds'])) {
                foreach ($in['supplierIds'] as $sid) $supplierIds[] = v_id($sid, 'supplier');
            }
        } elseif (array_key_exists('supplierId', $in)) {
            $supplierIds = ($in['supplierId'] === '' || $in['supplierId'] === null) ? [] : [v_id($in['supplierId'], 'supplier')];
        }
        if ($touchSuppliers) $supplierIds = check_suppliers($pdo, $supplierIds);
        if ($categoryId !== null) {
            $st = $pdo->prepare('SELECT 1 FROM category WHERE category_id = ?');
            $st->execute([$categoryId]);
            if (!$st->fetch()) fail('Category not found.', 422);
        }
        $pdo->beginTransaction();
        $st = $pdo->prepare('UPDATE product SET product_name=?, category_id=?, selling_price=?, minimum_stock=? WHERE product_id=?');
        $st->execute([$name, $categoryId, $price, $reorder, $id]);
        if ($touchSuppliers) {
            $pdo->prepare('DELETE FROM product_supplier WHERE product_id = ?')->execute([$id]);
            if (count($supplierIds) > 0) {
                $stL = $pdo->prepare('INSERT INTO product_supplier (product_id, supplier_id) VALUES (?, ?)');
                foreach ($supplierIds as $sid) $stL->execute([$id, $sid]);
            }
        }
        if ($wantStock !== null) {
            $diff = $wantStock - live_stock($pdo, $id);
            if ($diff > 0) {
                // Manual top-up: new lot at the entered cost (avg stays derived).
                $st = $pdo->prepare('INSERT INTO stock_lot (product_id, purchase_id, qty_bought, qty_left, unit_cost, expiry_date, received_at) VALUES (?, NULL, ?, ?, ?, ?, NOW())');
                $st->execute([$id, $diff, $diff, $enteredCost ?? $price, $expiry]);
            } elseif ($diff < 0) {
                // Manual write-off consumes oldest batches first (no sale record).
                consume_fifo($pdo, $id, -$diff);
            }
        }
        $pdo->commit();
        send_json(['ok' => true, 'data' => product_row($pdo, $id)]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'product');
        $st = $pdo->prepare('SELECT 1 FROM product WHERE product_id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) fail('Product not found.', 404);
        try {
            $pdo->prepare('DELETE FROM product WHERE product_id = ?')->execute([$id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('Cannot delete: this product has purchase, sale or batch records.', 409);
            throw $e;
        }
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && $e->getCode() === '23000' && stripos($e->getMessage(), 'Duplicate entry') !== false && stripos($e->getMessage(), 'product_name') !== false) {
        fail('A product with this name already exists. Open it from the list and tick the extra supplier instead.', 409);
    }
    fail('Database error: ' . $e->getMessage(), 500);
}
