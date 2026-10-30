<?php
// api/auth.php — role-based login (Owner / Manager / Cashier) + me / logout.
// Login screen sends {username, password}. The backend finds the account
// and returns its role itself (admin -> owner, manager -> manager,
// staff -> cashier); an optional `role` field, when sent, must match the
// account's role or login is rejected with 403.
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// DB enum value => login-screen key + display label.
const ROLE_MAP = [
    'admin'   => ['key' => 'owner',   'label' => 'Owner'],
    'manager' => ['key' => 'manager', 'label' => 'Manager'],
    'staff'   => ['key' => 'cashier', 'label' => 'Cashier'],
];

function public_user(array $u): array {
    $m = ROLE_MAP[$u['role']] ?? ['key' => $u['role'], 'label' => ucfirst($u['role'])];
    return ['username' => $u['username'], 'full_name' => $u['full_name'],
            'email' => (string)($u['email'] ?? ''),
            'role' => $m['key'], 'role_label' => $m['label']];
}

$action = $_GET['action'] ?? '';
if ($action === 'me') {
    if (!empty($_SESSION['username'])) {
        try {
            $st = db()->prepare('SELECT * FROM app_user WHERE username = ? LIMIT 1');
            $st->execute([$_SESSION['username']]);
            $account = $st->fetch();
            if ($account && (int)$account['is_active'] === 1) {
                $_SESSION['user_id'] = (int)$account['user_id']; // upgrade sessions created before user_id was stored
                $_SESSION['full_name'] = $account['full_name'];
                send_json(['ok' => true, 'user' => public_user($account)]);
            }
            session_unset();
            session_destroy();
        } catch (Throwable $e) {
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }
    send_json(['ok' => false], 401);
}
if ($action === 'logout') {
    session_unset();
    session_destroy();
    send_json(['ok' => true]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST with {username, password, role}.', 405);

$in = json_input() + $_POST;
$username = v_username($in['username'] ?? '');
$password = (string)($in['password'] ?? '');
$wantRole = strtolower(trim((string)($in['role'] ?? ''))); // owner|manager|cashier
if ($password === '' || mb_strlen($password) > 72) fail('Username and password are required.', 422); // 72 = bcrypt limit
if ($wantRole !== '' && !in_array($wantRole, ['owner', 'manager', 'cashier'], true)) {
    fail('Unknown role. Choose Owner, Manager or Cashier.', 422);
}

try {
    $pdo = db();
    login_attempt_ready($pdo);

    // Locked out? MAX_FAILED_ATTEMPTS wrong passwords inside LOCK_MINUTES.
    // Checked before the password so a locked account cannot be guessed at.
    if (failed_login_attempts($pdo, $username) >= MAX_FAILED_ATTEMPTS) {
        $mins = max(1, login_lock_minutes($pdo, $username));
        fail('Too many wrong password attempts. This account is locked for about ' . $mins .
             ' more minute(s). Ask the Owner or Manager to unlock it on Staff & Access.', 429);
    }

    $st = $pdo->prepare('SELECT * FROM app_user WHERE username = ? AND is_active = 1 LIMIT 1');
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        record_login_attempt($pdo, $username, false);
        $left = MAX_FAILED_ATTEMPTS - (failed_login_attempts($pdo, $username));
        if ($left <= 0) {
            fail('Too many wrong password attempts. This account is locked for ' . LOCK_MINUTES .
                 ' minutes. Ask the Owner or Manager to unlock it on Staff & Access.', 429);
        }
        fail('Invalid username or password. ' . $left . ' attempt(s) left before this account locks.', 401);
    }
    $pub = public_user($u);
    if ($wantRole !== '' && $pub['role'] !== $wantRole) {
        fail('This account is not a ' . ucfirst($wantRole) . ' account. Use the ' . $pub['role_label'] . ' login instead.', 403);
    }
    clear_login_attempts($pdo, $username); // a good password resets the run of failures
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$u['user_id'];
    $_SESSION['username']   = $u['username'];
    $_SESSION['full_name']  = $u['full_name'];
    $_SESSION['role_key']   = $pub['role'];
    $_SESSION['role_label'] = $pub['role_label'];
    send_json(['ok' => true, 'user' => $pub]);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
