<?php
// api/categories.php — CRUD for categories.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = db();
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $st = $pdo->prepare('SELECT * FROM categories WHERE category_id = ?');
            $st->execute([(int)$_GET['id']]);
            $r = $st->fetch();
            if (!$r) fail('Category not found.', 404);
            send_json(['ok' => true, 'data' => map_category($r)]);
        }
        $rows = $pdo->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();
        // attach live product counts so the UI can show them without a second call
        $counts = $pdo->query('SELECT category_id, COUNT(*) c FROM products GROUP BY category_id')->fetchAll(PDO::FETCH_KEY_PAIR);
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
        $st = $pdo->prepare('INSERT INTO categories (category_name, description) VALUES (?, ?)');
        $st->execute([$name, $desc !== '' ? $desc : null]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM categories WHERE category_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_category($st->fetch())], 201);
    }
    if ($method === 'PUT') {
        $id = v_id($_GET['id'] ?? 0, 'category');
        $in = json_input();
        $name = v_name($in['name'] ?? $in['category_name'] ?? '', 'Category name', 2, 100);
        $desc = clean_text($in['description'] ?? '');
        if (mb_strlen($desc) > 1000) fail('Description is too long (max 1000 characters).', 422);
        $st = $pdo->prepare('UPDATE categories SET category_name = ?, description = ? WHERE category_id = ?');
        $st->execute([$name, $desc !== '' ? $desc : null, $id]);
        $st = $pdo->prepare('SELECT * FROM categories WHERE category_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_category($st->fetch())]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'category');
        $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) fail('Cannot delete: products are still assigned to this category.', 409);
        $pdo->prepare('DELETE FROM categories WHERE category_id = ?')->execute([$id]);
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
