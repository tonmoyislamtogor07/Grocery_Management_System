<?php
// api/customers.php — CRUD for customers.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];
try {
    $pdo = db();
    // Cashier may READ + ADD customers (billing need), never edit/delete.
    $role = require_login();
    if (in_array($method, ['PUT', 'DELETE'], true)) require_roles(['owner', 'manager'], 'edit customers');
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $st = $pdo->prepare('SELECT * FROM customers WHERE customer_id = ?');
            $st->execute([(int)$_GET['id']]);
            $r = $st->fetch();
            if (!$r) fail('Customer not found.', 404);
            send_json(['ok' => true, 'data' => map_customer($r)]);
        }
        $rows = $pdo->query('SELECT * FROM customers ORDER BY customer_name')->fetchAll();
        $counts = $pdo->query('SELECT customer_id, COUNT(*) c FROM sales WHERE customer_id IS NOT NULL GROUP BY customer_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $data = array_map(function ($r) use ($counts) {
            $m = map_customer($r);
            $m['purchaseCount'] = (int)($counts[$r['customer_id']] ?? 0);
            return $m;
        }, $rows);
        send_json(['ok' => true, 'data' => $data]);
    }
    if ($method === 'POST') {
        $in = json_input();
        $name = v_name($in['name'] ?? '', 'Customer name', 2, 150);
        $phone = v_phone($in['phone'] ?? '', 'Phone');
        $st = $pdo->prepare('INSERT INTO customers (customer_name, phone) VALUES (?, ?)');
        $st->execute([$name, $phone]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM customers WHERE customer_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_customer($st->fetch())], 201);
    }
    if ($method === 'PUT') {
        $id = v_id($_GET['id'] ?? 0, 'customer');
        $in = json_input();
        $name = v_name($in['name'] ?? '', 'Customer name', 2, 150);
        $phone = v_phone($in['phone'] ?? '', 'Phone');
        $pdo->prepare('UPDATE customers SET customer_name=?, phone=? WHERE customer_id=?')
            ->execute([$name, $phone, $id]);
        $st = $pdo->prepare('SELECT * FROM customers WHERE customer_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_customer($st->fetch())]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'customer');
        $st = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE customer_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) fail('Cannot delete: this customer has sales records.', 409);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$id]);
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) { fail('Database error: ' . $e->getMessage(), 500); }
