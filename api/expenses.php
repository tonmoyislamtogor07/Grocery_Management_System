<?php
// api/expenses.php — CRUD for expense.
// Read (GET) is needed by every manager screen that shows profit
// (dashboard stats, profit report), so owner + manager may read.
// Writes (POST/PUT/DELETE) are owner-only payouts: the Expenses page
// itself is in the owner's nav only (app.js bounces others away).
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];
try {
    $pdo = db();
    $role = require_login();
    // Cashier: fully blocked here (no payout reads or writes).
    if ($method === 'GET') require_roles(['owner', 'manager'], 'view expense');
    elseif (in_array($method, ['POST', 'PUT', 'DELETE'], true)) require_roles(['owner'], 'manage expense');
    else fail('Method not allowed.', 405);
    if ($method === 'GET') {
        $rows = $pdo->query('SELECT * FROM expense ORDER BY expense_date DESC, expense_id DESC')->fetchAll();
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
        $st = $pdo->prepare('INSERT INTO expense (expense_type, description, amount, expense_date) VALUES (?, ?, ?, ?)');
        $st->execute([$category, $description !== '' ? $description : null, $amount, $date]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM expense WHERE expense_id = ?');
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
        $st = $pdo->prepare('SELECT 1 FROM expense WHERE expense_id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) fail('Expense not found.', 404);
        $pdo->prepare('UPDATE expense SET expense_type=?, description=?, amount=?, expense_date=? WHERE expense_id=?')
            ->execute([$category, $description !== '' ? $description : null, $amount, $date, $id]);
        $st = $pdo->prepare('SELECT * FROM expense WHERE expense_id = ?');
        $st->execute([$id]);
        send_json(['ok' => true, 'data' => map_expense($st->fetch())]);
    }
    if ($method === 'DELETE') {
        $id = v_id($_GET['id'] ?? 0, 'expense');
        $st = $pdo->prepare('SELECT 1 FROM expense WHERE expense_id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) fail('Expense not found.', 404);
        $pdo->prepare('DELETE FROM expense WHERE expense_id = ?')->execute([$id]);
        send_json(['ok' => true]);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) { fail('Database error: ' . $e->getMessage(), 500); }
