<?php
// api/auth.php — role-based login (Owner / Manager / Cashier) + me / logout.
// Login screen sends {username, password, role}. The account's DB role must
// match the selected tab, otherwise login is rejected with 403.
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
            'role' => $m['key'], 'role_label' => $m['label']];
}

$action = $_GET['action'] ?? '';
if ($action === 'me') {
    if (!empty($_SESSION['username'])) {
        send_json(['ok' => true, 'user' => [
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'] ?? '',
            'role' => $_SESSION['role_key'] ?? '',
            'role_label' => $_SESSION['role_label'] ?? '',
        ]]);
    }
    send_json(['ok' => false], 401);
}
if ($action === 'logout') {
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
    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) fail('Invalid username or password.', 401);
    $pub = public_user($u);
    if ($wantRole !== '' && $pub['role'] !== $wantRole) {
        fail('This account is not a ' . ucfirst($wantRole) . ' account. Use the ' . $pub['role_label'] . ' login instead.', 403);
    }
    $_SESSION['username']   = $u['username'];
    $_SESSION['full_name']  = $u['full_name'];
    $_SESSION['role_key']   = $pub['role'];
    $_SESSION['role_label'] = $pub['role_label'];
    send_json(['ok' => true, 'user' => $pub]);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
