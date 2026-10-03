<?php
// api/suppliers.php — CRUD for suppliers.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];
try {
    $pdo = db();
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $st = $pdo->prepare('SELECT * FROM suppliers WHERE supplier_id = ?');
            $st->execute([(int)$_GET['id']]);
            $r = $st->fetch();
            if (!$r) fail('Supplier not found.', 404);
            send_json(['ok' => true, 'data' => map_supplier($r)]);
        }
        $rows = $pdo->query('SELECT * FROM suppliers ORDER BY supplier_name')->fetchAll();
        $counts = $pdo->query('SELECT supplier_id, COUNT(*) c FROM purchases GROUP BY supplier_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        try { $prodCounts = $pdo->query('SELECT supplier_id, COUNT(DISTINCT product_id) c FROM product_suppliers GROUP BY supplier_id')->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (Throwable $e) { $prodCounts = []; }
        $data = array_map(function ($r) use ($counts, $prodCounts) {
            $m = map_supplier($r);
            $m['purchaseCount'] = (int)($counts[$r['supplier_id']] ?? 0);
            $m['productCount'] = (int)($prodCounts[$r['supplier_id']] ?? 0);
            return $m;
        }, $rows);
        send_json(['ok' => true, 'data' => $data]);
    }
    if ($method === 'POST') {
        $in = json_input();
        $name = v_name($in['name'] ?? '', 'Supplier name', 2, 150);
        $phone = v_phone($in['phone'] ?? '', 'Phone');
        $address = clean_text($in['address'] ?? '');
        if (mb_strlen($address) > 1000) fail('Address is too long (max 1000 characters).', 422);
        $st = $pdo->prepare('INSERT INTO suppliers (supplier_name, phone, address) VALUES (?, ?, ?)');
        $st->execute([$name, $phone, $address !== '' ? $address : null]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM suppliers WHERE supplier_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_supplier($st->fetch())], 201);
    }
    if ($method === 'PUT') {
        $id = v_id($_GET['id'] ?? 0, 'supplier');
        $in = json_input();
        $name = v_name($in['name'] ?? '', 'Supplier name', 2, 150);
        $phone = v_phone($in['phone'] ?? '', 'Phone');
        $address = clean_text($in['address'] ?? '');
        if (mb_strlen($address) > 1000) fail('Address is too long (max 1000 characters).', 422);
        $pdo->prepare('UPDATE suppliers SET supplier_name=?, phone=?, address=? WHERE supplier_id=?')
            ->execute([$name, $phone, $address !== '' ? $address : null, $id]);
        $st = $pdo->prepare('SELECT * FROM suppliers WHERE supplier_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_supplier($st->fetch())]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'supplier');
        $st = $pdo->prepare('SELECT COUNT(*) FROM purchases WHERE supplier_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) fail('Cannot delete: this supplier has purchase records.', 409);
        $pdo->prepare('DELETE FROM suppliers WHERE supplier_id = ?')->execute([$id]);
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) { fail('Database error: ' . $e->getMessage(), 500); }
