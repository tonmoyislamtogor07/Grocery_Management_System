<?php
// api/auth.php — role-based login (Owner / Manager / Cashier) + me / logout.
// Login screen sends {username, password}. The backend finds the account
// and returns its role itself (admin -> owner, manager -> manager,
// staff -> cashier); an optional `role` field, when sent, must match the
// account's role or login is rejected with 403.
// Session cookie is a browser-session cookie (lifetime 0): nothing is kept on
// disk once the browser is closed, and signing out clears the cookie too.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_lifetime', '0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/db.php';

// Fully end the login: clear the server session AND expire the cookie, so a
// reload cannot walk back in with the old session id.
function end_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
}

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
            'phone' => (string)($u['phone'] ?? ''),
            'role' => $m['key'], 'role_label' => $m['label'],
            // true while an administrator's temporary password is still in use
            'must_change_password' => ((int)($u['must_change_password'] ?? 0) === 1)];
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
                $_SESSION['must_change_password'] = ((int)($account['must_change_password'] ?? 0) === 1) ? 1 : 0;
                send_json(['ok' => true, 'user' => public_user($account)]);
            }
            end_session();
        } catch (Throwable $e) {
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }
    send_json(['ok' => false], 401);
}
if ($action === 'logout') {
    end_session();
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
    $_SESSION['must_change_password'] = $pub['must_change_password'] ? 1 : 0;
    send_json(['ok' => true, 'user' => $pub]);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
