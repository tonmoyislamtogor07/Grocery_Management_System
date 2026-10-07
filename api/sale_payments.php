<?php
// api/sale_payments.php — due collection on credit sale.
// Normalized: due = (SUM(lines) - discount) - SUM(payments), all computed.
// GET ?sale_id=123 -> payment history for one bill (oldest first).
// POST {sale_id, amount, date?} -> records a payment. Amount can never
// exceed the remaining due.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$method = $_SERVER['REQUEST_METHOD'];

function bill_due(PDO $pdo, int $sid): ?array {
    $st = $pdo->prepare('SELECT discount FROM sale WHERE sale_id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) return null;
    $st = $pdo->prepare('SELECT COALESCE(SUM(quantity * unit_price),0) FROM sale_detail WHERE sale_id = ?');
    $st->execute([$sid]);
    $final = round((float)$st->fetchColumn() - (float)$s['discount'], 2);
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM sale_payment WHERE sale_id = ?');
    $st->execute([$sid]);
    $paid = round((float)$st->fetchColumn(), 2);
    return ['final' => $final, 'paid' => $paid, 'due' => max(0, round($final - $paid, 2))];
}

try {
    $pdo = db();
    // Due collection happens at the counter: every role may read + record.
    $role = require_login();
    if ($method === 'GET') {
        $sid = v_id($_GET['sale_id'] ?? $_GET['saleId'] ?? 0, 'sale');
        $st = $pdo->prepare('SELECT 1 FROM sale WHERE sale_id = ?');
        $st->execute([$sid]);
        if (!$st->fetch()) fail('Sale not found.', 404);
        $st = $pdo->prepare('SELECT payment_id, amount, paid_at FROM sale_payment WHERE sale_id = ? ORDER BY paid_at ASC, payment_id ASC');
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
        $bill = bill_due($pdo, $sid);
        if ($bill === null) { $pdo->rollBack(); fail('Sale not found.', 404); }
        if ($bill['due'] <= 0) { $pdo->rollBack(); fail('This bill has no due left.', 409); }
        if ($amount - $bill['due'] > 0.009) { $pdo->rollBack(); fail('Amount exceeds the due of ' . number_format($bill['due'], 2) . '.', 422); }
        $st = $pdo->prepare('INSERT INTO sale_payment (sale_id, amount, paid_at) VALUES (?, ?, ?)');
        $st->execute([$sid, $amount, $paidAt]);
        $pdo->commit();
        $bill = bill_due($pdo, $sid);
        send_json(['ok' => true, 'data' => [
            'saleId' => $sid,
            'cashReceived' => $bill['paid'],
            'due' => $bill['due'],
        ]], 201);
    }
    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
