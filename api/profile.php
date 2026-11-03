<?php
// api/profile.php — the signed-in person's OWN contact details.
//
// Any role may change its own email + phone at any time (GET / POST / PUT).
// Why: the reset email belongs to that person, and a shop should not need the
// Owner to fix a typo or a changed address. A phone number or inbox can be
// lost, so it must be editable later instead of being frozen at hire time.
//
// What stays with the Owner on Staff & Access: name, role, activation — those
// appear on bills and in the audit trail, so a person cannot rewrite them.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/db.php';

const PROFILE_ROLE_KEY = ['admin' => 'owner', 'manager' => 'manager', 'staff' => 'cashier'];

// Reads the row straight from the DB so the session can never go stale.
function profile_me(PDO $pdo): array {
    if (empty($_SESSION['username']) || empty($_SESSION['user_id'])) fail('Please log in first.', 401);
    $st = $pdo->prepare('SELECT user_id, username, full_name, email, phone, role, is_active, must_change_password
        FROM app_user WHERE user_id = ? LIMIT 1');
    $st->execute([(int)$_SESSION['user_id']]);
    $row = $st->fetch();
    if (!$row || (int)$row['is_active'] !== 1) fail('Please log in again.', 401);
    return $row;
}

function map_profile(array $r): array {
    return [
        'id'       => (int)$r['user_id'],
        'username' => (string)$r['username'],
        'fullName' => (string)($r['full_name'] ?? ''),
        'email'    => (string)($r['email'] ?? ''),
        'phone'    => (string)($r['phone'] ?? ''),
        'role'     => PROFILE_ROLE_KEY[(string)$r['role']] ?? (string)$r['role'],
        'mustChangePassword' => ((int)($r['must_change_password'] ?? 0) === 1),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
try {
    $pdo = db();
    password_schema_ready($pdo);
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $me = profile_me($pdo);

    if ($method === 'GET') {
        send_json(['ok' => true, 'data' => map_profile($me)]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $in = json_input();
        $email = v_email($in['email'] ?? '', 'Email');
        $phone = v_phone($in['phone'] ?? '', 'Phone');

        $oldEmail = (string)($me['email'] ?? '');
        $oldPhone = (string)($me['phone'] ?? '');
        $newEmail = (string)($email ?? '');
        $newPhone = (string)($phone ?? '');
        if ($newEmail === $oldEmail && $newPhone === $oldPhone) {
            send_json(['ok' => true, 'data' => map_profile($me), 'message' => 'Nothing changed.']);
        }

        $pdo->prepare('UPDATE app_user SET email = ?, phone = ? WHERE user_id = ?')
            ->execute([$email, $phone, (int)$me['user_id']]);

        $changes = [];
        if ($newEmail !== $oldEmail) $changes[] = $newEmail === '' ? 'Reset email removed' : 'Reset email added/updated';
        if ($newPhone !== $oldPhone) $changes[] = $newPhone === '' ? 'Phone removed' : 'Phone added/updated';

        $roleKey = PROFILE_ROLE_KEY[(string)$me['role']] ?? (string)$me['role'];
        $name = (string)($me['full_name'] ?? '');
        log_staff($pdo, (int)$me['user_id'], $name, (string)$me['username'], $roleKey,
            (int)$me['user_id'], $name, (string)$me['username'], $roleKey,
            'profile_updated', implode(' · ', $changes));

        $me['email'] = $email;
        $me['phone'] = $phone;
        send_json(['ok' => true, 'data' => map_profile($me),
            'message' => 'Contact details saved.']);
    }

    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
