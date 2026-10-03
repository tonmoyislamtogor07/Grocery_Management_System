<?php
// api/sale_payments.php — due collection on credit sales.
// GET ?sale_id=123 -> payment history for one bill (oldest first).
// POST {sale_id, amount, date?} -> records a payment, bumps
// sales.cash_received in the same transaction. Amount can never
// exceed the remaining due (final_amount - cash_received).
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = db();
    // Due collection happens at the counter: every role may read + record.
    $role = require_login();
    if ($method === 'GET') {
        $sid = v_id($_GET['sale_id'] ?? $_GET['saleId'] ?? 0, 'sale');
        $st = $pdo->prepare('SELECT payment_id, amount, paid_at FROM sale_payments WHERE sale_id = ? ORDER BY paid_at ASC, payment_id ASC');
        $st->execute([$sid]);
        $data = array_map(function ($r) {
            return ['id' => (int)$r['payment_id'], 'amount' => (float)$r['amount'],
                    'date' => substr((string)$r['paid_at'], 0, 10)];
        }, $st->fetchAll());
        send_json(['ok' => true, 'data' => $data]);
    }
    if ($method === 'POST') {
        $in = json_input();
        $sid = v_id($in['sale_id'] ?? $in['saleId'] ?? 0, 'sale');
        $amount = v_money($in['amount'] ?? null, 'Amount', 0, false); // must be > 0
        $paidAt = v_datetime($in['date'] ?? date('Y-m-d'), 'Payment date');
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT final_amount, cash_received FROM sales WHERE sale_id = ? FOR UPDATE');
        $st->execute([$sid]);
        $s = $st->fetch();
        if (!$s) { $pdo->rollBack(); fail('Sale not found.', 404); }
        $due = round((float)$s['final_amount'] - (float)$s['cash_received'], 2);
        if ($due <= 0) { $pdo->rollBack(); fail('This bill has no due left.', 409); }
        if ($amount - $due > 0.009) { $pdo->rollBack(); fail('Amount exceeds the due of ' . number_format($due, 2) . '.', 422); }
        $st = $pdo->prepare('INSERT INTO sale_payments (sale_id, amount, paid_at) VALUES (?, ?, ?)');
        $st->execute([$sid, $amount, $paidAt]);
        $pdo->prepare('UPDATE sales SET cash_received = ROUND(cash_received + ?, 2) WHERE sale_id = ?')->execute([$amount, $sid]);
        $pdo->commit();
        $st = $pdo->prepare('SELECT final_amount, cash_received FROM sales WHERE sale_id = ?');
        $st->execute([$sid]);
        $row = $st->fetch();
        send_json(['ok' => true, 'data' => [
            'saleId' => $sid,
            'cashReceived' => (float)$row['cash_received'],
            'due' => max(0, round((float)$row['final_amount'] - (float)$row['cash_received'], 2)),
        ]], 201);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
