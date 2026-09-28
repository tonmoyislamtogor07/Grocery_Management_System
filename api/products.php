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
            send_json(['ok' => true, 'data' => map_product($r)]);
        }
        $rows = $pdo->query('SELECT * FROM products ORDER BY product_name')->fetchAll();
        send_json(['ok' => true, 'data' => array_map('map_product', $rows)]);
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
        $st = $pdo->prepare('SELECT 1 FROM categories WHERE category_id = ?');
        $st->execute([$categoryId]);
        if (!$st->fetch()) fail('Category not found.', 422);
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO products (product_name, category_id, selling_price, purchase_price, stock_quantity, minimum_stock, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$name, $categoryId, $price, $purchasePrice, $stock, $reorder, $expiry]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        $st = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_product($st->fetch())], 201);
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
        if ($categoryId !== null) {
            $st = $pdo->prepare('SELECT 1 FROM categories WHERE category_id = ?');
            $st->execute([$categoryId]);
            if (!$st->fetch()) fail('Category not found.', 422);
        }
        $pdo->beginTransaction();
        $st = $pdo->prepare('UPDATE products SET product_name=?, category_id=?, selling_price=?, purchase_price=?, stock_quantity=?, minimum_stock=?, expiry_date=? WHERE product_id=?');
        $st->execute([$name, $categoryId, $price, $purchasePrice, $stock, $reorder, $expiry, $id]);
        $pdo->commit();
        $st = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_product($st->fetch())]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'product');
        try {
            $pdo->prepare('DELETE FROM products WHERE product_id = ?')->execute([$id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('Cannot delete: this product appears in purchase or sales records.', 409);
            throw $e;
        }
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
