<?php
// api/products.php — CRUD for products.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = db();
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $st = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
            $st->execute([(int)$_GET['id']]);
            $r = $st->fetch();
            if (!$r) fail('Product not found.', 404);
            send_json(['ok' => true, 'data' => map_product($r, product_supplier_ids($pdo, (int)$r['product_id']))]);
        }
        $rows = $pdo->query('SELECT * FROM products ORDER BY product_name')->fetchAll();
        $supMap = product_suppliers_map($pdo, array_column($rows, 'product_id'));
        send_json(['ok' => true, 'data' => array_map(function ($r) use ($supMap) {
            return map_product($r, $supMap[(int)$r['product_id']] ?? []);
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
        $purchasePrice = isset($in['purchasePrice']) && $in['purchasePrice'] !== '' ? v_money($in['purchasePrice'], 'Purchase price') : $price;
        // Option B: multiple allowed suppliers (optional). Accepts supplierIds[]; single supplierId kept for backward compat.
        $supplierIds = [];
        if (isset($in['supplierIds']) && is_array($in['supplierIds'])) {
            foreach ($in['supplierIds'] as $sid) $supplierIds[] = v_id($sid, 'supplier');
        } elseif (isset($in['supplierId']) && $in['supplierId'] !== '' && $in['supplierId'] !== null) {
            $supplierIds[] = v_id($in['supplierId'], 'supplier');
        }
        $supplierIds = array_values(array_unique($supplierIds));
        $st = $pdo->prepare('SELECT 1 FROM categories WHERE category_id = ?');
        $st->execute([$categoryId]);
        if (!$st->fetch()) fail('Category not found.', 422);
        if (count($supplierIds) > 0) {
            $stS = $pdo->prepare('SELECT 1 FROM suppliers WHERE supplier_id = ?');
            foreach ($supplierIds as $sid) {
                $stS->execute([$sid]);
                if (!$stS->fetch()) fail('Supplier #' . $sid . ' does not exist.', 422);
            }
        }
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO products (product_name, category_id, selling_price, purchase_price, stock_quantity, minimum_stock, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$name, $categoryId, $price, $purchasePrice, $stock, $reorder, $expiry]);
        $id = (int)$pdo->lastInsertId();
        if (count($supplierIds) > 0) {
            $stL = $pdo->prepare('INSERT INTO product_suppliers (product_id, supplier_id) VALUES (?, ?)');
            foreach ($supplierIds as $sid) $stL->execute([$id, $sid]);
        }
        if ($stock > 0) {
            $stL = $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_changed) VALUES (?, 'opening', ?)");
            $stL->execute([$id, $stock]);
        }
        $pdo->commit();
        $st = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_product($st->fetch(), product_supplier_ids($pdo, $id))], 201);
    }
    if ($method === 'PUT') {
        $id = v_id($_GET['id'] ?? 0, 'product');
        $in = json_input();
        $st = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) fail('Product not found.', 404);
        $name = array_key_exists('name', $in) ? v_name($in['name'], 'Product name', 2, 200) : $old['product_name'];
        $categoryId = array_key_exists('categoryId', $in) ? v_id($in['categoryId'], 'category') : $old['category_id'];
        $price = array_key_exists('price', $in) ? v_money($in['price'], 'Selling price') : (float)$old['selling_price'];
        $stock = array_key_exists('stock', $in) ? v_qty($in['stock'], 'Stock', 0) : (int)$old['stock_quantity'];
        $purchasePrice = array_key_exists('purchasePrice', $in) ? v_money($in['purchasePrice'], 'Purchase price') : (float)$old['purchase_price'];
        $reorder = array_key_exists('reorderLevel', $in) ? v_qty($in['reorderLevel'], 'Reorder level', 0) : (int)$old['minimum_stock'];
        $expiry = array_key_exists('expiry', $in) ? v_optional_date($in['expiry'], 'Expiry date') : $old['expiry_date'];
        // Option B: replace allowed-supplier links only when the key is sent.
        $touchSuppliers = array_key_exists('supplierIds', $in) || array_key_exists('supplierId', $in);
        $supplierIds = product_supplier_ids($pdo, $id);
        if (array_key_exists('supplierIds', $in)) {
            $supplierIds = [];
            if (is_array($in['supplierIds'])) {
                foreach ($in['supplierIds'] as $sid) $supplierIds[] = v_id($sid, 'supplier');
            }
            $supplierIds = array_values(array_unique($supplierIds));
        } elseif (array_key_exists('supplierId', $in)) {
            $supplierIds = ($in['supplierId'] === '' || $in['supplierId'] === null) ? [] : [v_id($in['supplierId'], 'supplier')];
        }
        if ($touchSuppliers && count($supplierIds) > 0) {
            $stS = $pdo->prepare('SELECT 1 FROM suppliers WHERE supplier_id = ?');
            foreach ($supplierIds as $sid) {
                $stS->execute([$sid]);
                if (!$stS->fetch()) fail('Supplier #' . $sid . ' does not exist.', 422);
            }
        }
        if ($categoryId !== null) {
            $st = $pdo->prepare('SELECT 1 FROM categories WHERE category_id = ?');
            $st->execute([$categoryId]);
            if (!$st->fetch()) fail('Category not found.', 422);
        }
        $pdo->beginTransaction();
        $st = $pdo->prepare('UPDATE products SET product_name=?, category_id=?, selling_price=?, purchase_price=?, stock_quantity=?, minimum_stock=?, expiry_date=? WHERE product_id=?');
        $st->execute([$name, $categoryId, $price, $purchasePrice, $stock, $reorder, $expiry, $id]);
        if ($touchSuppliers) {
            $pdo->prepare('DELETE FROM product_suppliers WHERE product_id = ?')->execute([$id]);
            if (count($supplierIds) > 0) {
                $stL = $pdo->prepare('INSERT INTO product_suppliers (product_id, supplier_id) VALUES (?, ?)');
                foreach ($supplierIds as $sid) $stL->execute([$id, $sid]);
            }
        }
        $diff = $stock - (int)$old['stock_quantity'];
        if ($diff !== 0) {
            $stL = $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_changed) VALUES (?, 'adjust', ?)");
            $stL->execute([$id, $diff]);
        }
        $pdo->commit();
        $st = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_product($st->fetch(), product_supplier_ids($pdo, $id))]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'product');
        try {
            $pdo->prepare('DELETE FROM products WHERE product_id = ?')->execute([$id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('Cannot delete: this product appears in purchase, sales or stock records.', 409);
            throw $e;
        }
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && $e->getCode() === '23000' && stripos($e->getMessage(), 'uq_product_name') !== false) {
        fail('A product with this name already exists. Open it from the list and tick the extra supplier instead.', 409);
    }
    fail('Database error: ' . $e->getMessage(), 500);
}
