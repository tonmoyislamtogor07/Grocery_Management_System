<?php
// api/expenses.php — CRUD for expenses.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];
try {
    $pdo = db();
    if ($method === 'GET') {
        $rows = $pdo->query('SELECT * FROM expenses ORDER BY expense_date DESC, expense_id DESC')->fetchAll();
        send_json(['ok' => true, 'data' => array_map('map_expense', $rows)]);
    }
    if ($method === 'POST') {
        $in = json_input();
        $category = clean_text($in['category'] ?? 'General');
        if ($category === '' || mb_strlen($category) > 100) fail('Expense category must be 1–100 characters.', 422);
        $date = v_date($in['date'] ?? '', 'Expense date');
        $description = clean_text($in['description'] ?? '');
        if (mb_strlen($description) > 2000) fail('Description is too long (max 2000 characters).', 422);
        $amount = v_money($in['amount'] ?? null, 'Amount', 0, false); // must be > 0
        $st = $pdo->prepare('INSERT INTO expenses (expense_type, description, amount, expense_date) VALUES (?, ?, ?, ?)');
        $st->execute([$category, $description !== '' ? $description : null, $amount, $date]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM expenses WHERE expense_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_expense($st->fetch())], 201);
    }
    if ($method === 'PUT') {
        $id = v_id($_GET['id'] ?? 0, 'expense');
        $in = json_input();
        $category = clean_text($in['category'] ?? 'General');
        if ($category === '' || mb_strlen($category) > 100) fail('Expense category must be 1–100 characters.', 422);
        $date = v_date($in['date'] ?? '', 'Expense date');
        $description = clean_text($in['description'] ?? '');
        if (mb_strlen($description) > 2000) fail('Description is too long (max 2000 characters).', 422);
        $amount = v_money($in['amount'] ?? null, 'Amount', 0, false);
        $pdo->prepare('UPDATE expenses SET expense_type=?, description=?, amount=?, expense_date=? WHERE expense_id=?')
            ->execute([$category, $description !== '' ? $description : null, $amount, $date, $id]);
        $st = $pdo->prepare('SELECT * FROM expenses WHERE expense_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_expense($st->fetch())]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'expense');
        $pdo->prepare('DELETE FROM expenses WHERE expense_id = ?')->execute([$id]);
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) { fail('Database error: ' . $e->getMessage(), 500); }
