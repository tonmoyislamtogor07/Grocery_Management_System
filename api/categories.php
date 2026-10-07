<?php
// api/categories.php — CRUD for category.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = db();
    // Cashier: read-only here (no category writes).
    $role = require_login();
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) require_roles(['owner', 'manager'], 'manage category');
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $st = $pdo->prepare('SELECT * FROM category WHERE category_id = ?');
            $st->execute([(int)$_GET['id']]);
            $r = $st->fetch();
            if (!$r) fail('Category not found.', 404);
            send_json(['ok' => true, 'data' => map_category($r)]);
        }
        $rows = $pdo->query('SELECT * FROM category ORDER BY category_name')->fetchAll();
        // attach live product counts so the UI can show them without a second call
        $counts = $pdo->query('SELECT category_id, COUNT(*) c FROM product GROUP BY category_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $data = array_map(function ($r) use ($counts) {
            $m = map_category($r);
            $m['productCount'] = (int)($counts[$r['category_id']] ?? 0);
            return $m;
        }, $rows);
        send_json(['ok' => true, 'data' => $data]);
    }
    if ($method === 'POST') {
        $in = json_input();
        $name = v_name($in['name'] ?? $in['category_name'] ?? '', 'Category name', 2, 100);
        $desc = clean_text($in['description'] ?? '');
        if (mb_strlen($desc) > 1000) fail('Description is too long (max 1000 characters).', 422);
        try {
            $st = $pdo->prepare('INSERT INTO category (category_name, description) VALUES (?, ?)');
            $st->execute([$name, $desc !== '' ? $desc : null]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('A category with this name already exists.', 409);
            throw $e;
        }
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM category WHERE category_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_category($st->fetch())], 201);
    }
    if ($method === 'PUT') {
        $id = v_id($_GET['id'] ?? 0, 'category');
        $in = json_input();
        $name = v_name($in['name'] ?? $in['category_name'] ?? '', 'Category name', 2, 100);
        $desc = clean_text($in['description'] ?? '');
        if (mb_strlen($desc) > 1000) fail('Description is too long (max 1000 characters).', 422);
        $st = $pdo->prepare('SELECT 1 FROM category WHERE category_id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) fail('Category not found.', 404);
        try {
            $st = $pdo->prepare('UPDATE category SET category_name = ?, description = ? WHERE category_id = ?');
            $st->execute([$name, $desc !== '' ? $desc : null, $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('A category with this name already exists.', 409);
            throw $e;
        }
        $st = $pdo->prepare('SELECT * FROM category WHERE category_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_category($st->fetch())]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'category');
        $st = $pdo->prepare('SELECT 1 FROM category WHERE category_id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) fail('Category not found.', 404);
        $st = $pdo->prepare('SELECT COUNT(*) FROM product WHERE category_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) fail('Cannot delete: product are still assigned to this category.', 409);
        $pdo->prepare('DELETE FROM category WHERE category_id = ?')->execute([$id]);
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
