<?php
// api/password.php — password change + email reset. No approval step:
// a new password takes effect immediately for every role (Owner, Manager,
// Cashier). Strength is enforced by v_password() in config/db.php.
//
// Two ways to start a change:
//   1. Signed in → "My Account" → confirm the current password.
//   2. Signed out → "Forgot password?" on the login page → a 6-digit code
//      is emailed to the address on the account (10 min, 5 tries, one use).
// Nothing is stored while waiting — the new hash is written right away.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

const PW_CODE_MINUTES = 10;
const PW_CODE_TRIES   = 5;
const PW_CODE_RESEND  = 60; // seconds before another code can be requested
const PW_CODE_LENGTH  = 6;

const PW_ROLE_KEY = ['admin' => 'owner', 'manager' => 'manager', 'staff' => 'cashier'];

function pw_role_key(string $dbRole): string { return PW_ROLE_KEY[$dbRole] ?? $dbRole; }

function pw_find_user(PDO $pdo, string $username): ?array {
    $st = $pdo->prepare('SELECT user_id, username, full_name, email, role, is_active, password_hash
        FROM app_user WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $row = $st->fetch();
    return $row ?: null;
}

function pw_mask_email(string $email): string {
    $at = strpos($email, '@');
    if ($at === false || $at < 1) return $email;
    return substr($email, 0, 1) . '•••' . substr($email, $at);
}

function pw_apply_password(PDO $pdo, array $user, string $newPassword): void {
    // must_change_password = 0: the holder chose this password themselves, so
    // a temporary password set by an administrator is no longer in force.
    $pdo->prepare('UPDATE app_user SET password_hash = ?, must_change_password = 0 WHERE user_id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['user_id']]);
    $_SESSION['must_change_password'] = 0;
    clear_login_attempts($pdo, (string)$user['username']);
    // A finished reset cancels any older emailed code for this account.
    try {
        $pdo->prepare('UPDATE password_code SET used = 1 WHERE user_id = ? AND used = 0')
            ->execute([(int)$user['user_id']]);
    } catch (Throwable $e) {}
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = db();
    password_schema_ready($pdo);

    /* ------------------------- 1. request an email code ------------------------ */
    if ($method === 'POST' && $action === 'forgot') {
        $in = json_input();
        $username = v_username($in['username'] ?? '');
        $generic = 'If that account exists and has an email on file, a 6-digit code is on the way.';

        $user = pw_find_user($pdo, $username);
        if (!$user || empty($user['is_active'])) {
            send_json(['ok' => true, 'sent' => false, 'message' => $generic]); // no account enumeration
        }
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Still generic: this must not confirm that the username exists.
            send_json(['ok' => true, 'sent' => false,
                'message' => 'No code could be sent. If the account exists, either it has no email '
                    . 'on file yet (sign in and add one on My Account, or ask the Owner on Staff & Access) '
                    . 'or email is not set up on this server. An Owner or Manager can also set a temporary password for you.']);
        }

        $st = $pdo->prepare('SELECT created_at FROM password_code WHERE user_id = ? ORDER BY code_id DESC LIMIT 1');
        $st->execute([(int)$user['user_id']]);
        $last = (string)$st->fetchColumn();
        if ($last !== '' && (time() - strtotime($last)) < PW_CODE_RESEND) {
            send_json(['ok' => true, 'sent' => true, 'delivered' => false,
                'message' => 'A code was just sent to ' . pw_mask_email($email) . '. Wait a minute before asking for another.']);
        }

        $code = str_pad((string)random_int(0, 999999), PW_CODE_LENGTH, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO password_code (user_id, code_hash, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . PW_CODE_MINUTES . ' MINUTE))')
            ->execute([(int)$user['user_id'], password_hash($code, PASSWORD_DEFAULT)]);

        $name = (string)($user['full_name'] ?? $user['username']);
        $body = "Sufian Store — Grocery Management System\n\n"
            . "Hello " . $name . ",\n\n"
            . "Your password reset code is: " . $code . "\n\n"
            . "The code expires in " . PW_CODE_MINUTES . " minutes and can be used once.\n"
            . "If you did not ask for this, ignore this email — your password stays the same.\n\n"
            . "— Sufian Store, Islamnagar, Gollamari, Khulna\n";

        $delivered = send_mail($email, 'Sufian Store — password reset code', $body);
        send_json([
            'ok'        => true,
            'sent'      => true,
            'delivered' => $delivered,
            'email'     => pw_mask_email($email),
            'message'   => $delivered
                ? 'A 6-digit code was sent to ' . pw_mask_email($email) . '. It expires in ' . PW_CODE_MINUTES . ' minutes.'
                : 'Email is not set up on this server yet, so the code was written to storage/mail-outbox.log (local test mode).',
        ]);
    }

    /* ------------------------ 2. confirm the emailed code ---------------------- */
    if ($method === 'POST' && $action === 'verify') {
        $in = json_input();
        $username = v_username($in['username'] ?? '');
        $code = preg_replace('/\D+/', '', (string)($in['code'] ?? ''));
        $newPassword = v_password($in['newPassword'] ?? '', true);
        if (strlen($code) !== PW_CODE_LENGTH) fail('Enter the 6-digit code from the email.', 422);

        $user = pw_find_user($pdo, $username);
        if (!$user || empty($user['is_active'])) fail('Invalid or expired code.', 400);
        if (password_verify($newPassword, (string)$user['password_hash'])) {
            fail('The new password must be different from the current one.', 422);
        }

        $st = $pdo->prepare("SELECT * FROM password_code
            WHERE user_id = ? AND used = 0 AND expires_at > NOW()
            ORDER BY code_id DESC LIMIT 1");
        $st->execute([(int)$user['user_id']]);
        $row = $st->fetch();
        if (!$row) fail('That code has expired. Request a new one.', 400);
        if ((int)$row['attempts'] >= PW_CODE_TRIES) fail('Too many wrong codes. Request a new one.', 429);
        if (!password_verify($code, (string)$row['code_hash'])) {
            $pdo->prepare('UPDATE password_code SET attempts = attempts + 1 WHERE code_id = ?')
                ->execute([(int)$row['code_id']]);
            fail('Invalid or expired code.', 400);
        }
        $pdo->prepare('UPDATE password_code SET used = 1 WHERE code_id = ?')->execute([(int)$row['code_id']]);

        $roleKey = pw_role_key((string)$user['role']);
        $name = (string)($user['full_name'] ?? '');
        pw_apply_password($pdo, $user, $newPassword);
        log_staff($pdo, (int)$user['user_id'], $name, (string)$user['username'], $roleKey,
            (int)$user['user_id'], $name, (string)$user['username'], $roleKey,
            'password_changed', 'Password reset with an emailed code');
        send_json(['ok' => true, 'status' => 'applied',
            'message' => 'Password changed. You can sign in with the new password now.']);
    }

    /* ------------------- 3. signed-in change (current password) ---------------- */
    if ($method === 'POST' && $action === 'request') {
        $roleKey = require_login();
        $meId = current_user_id();
        $st = $pdo->prepare('SELECT user_id, username, full_name, role, password_hash FROM app_user WHERE user_id = ? LIMIT 1');
        $st->execute([$meId]);
        $user = $st->fetch();
        if (!$user) fail('Please log in again.', 401);

        $in = json_input();
        $current = (string)($in['currentPassword'] ?? '');
        $newPassword = v_password($in['newPassword'] ?? '', true);
        if ($current === '') fail('Enter your current password.', 422);
        if (!password_verify($current, (string)$user['password_hash'])) {
            fail('Current password is incorrect.', 401);
        }
        if (password_verify($newPassword, (string)$user['password_hash'])) {
            fail('The new password must be different from the current one.', 422);
        }

        $name = (string)($user['full_name'] ?? '');
        pw_apply_password($pdo, $user, $newPassword);
        log_staff($pdo, $meId, $name, (string)$user['username'], $roleKey,
            $meId, $name, (string)$user['username'], $roleKey,
            'password_changed', 'Password changed from My Account');
        send_json(['ok' => true, 'status' => 'applied',
            'message' => 'Password changed. Use the new password next time you sign in.']);
    }

    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
