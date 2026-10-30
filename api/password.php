<?php
// api/password.php — password change + email reset, with role approval.
//
// Rules (enforced HERE, server-side):
//   Owner   : changing/resetting the Owner password applies immediately —
//             nobody approves it.
//   Manager : a new password waits for the OWNER to approve.
//   Cashier : a new password waits for the MANAGER or OWNER to approve.
// Two ways to start a change:
//   1. Signed in → "My Account" → confirm the current password.
//   2. Signed out → "Forgot password?" on the login page → a 6-digit code
//      is emailed to the address on the account (10 min, 5 tries, one use).
// Only the bcrypt hash of the new password is stored while it waits.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

const PW_CODE_MINUTES = 10;
const PW_CODE_TRIES   = 5;
const PW_CODE_RESEND  = 60; // seconds before another code can be requested
const PW_CODE_LENGTH  = 6;

const PW_ROLE_KEY   = ['admin' => 'owner', 'manager' => 'manager', 'staff' => 'cashier'];
const PW_ROLE_LABEL = ['owner' => 'Owner', 'manager' => 'Manager', 'cashier' => 'Cashier'];

function pw_role_key(string $dbRole): string { return PW_ROLE_KEY[$dbRole] ?? $dbRole; }
function pw_role_label(string $key): string { return PW_ROLE_LABEL[$key] ?? ucfirst($key); }

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
    $pdo->prepare('UPDATE app_user SET password_hash = ? WHERE user_id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['user_id']]);
    clear_login_attempts($pdo, (string)$user['username']);
}

function pw_cancel_pending(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE password_request SET status = 'cancelled', decided_at = NOW()
        WHERE user_id = ? AND status = 'pending'")->execute([$userId]);
}

// Store the new password (hash only) until an approver signs off.
function pw_create_request(PDO $pdo, array $user, string $newPassword, string $mode): int {
    pw_cancel_pending($pdo, (int)$user['user_id']);
    $pdo->prepare("INSERT INTO password_request (user_id, mode, new_password_hash, status)
        VALUES (?, ?, ?, 'pending')")
        ->execute([(int)$user['user_id'], $mode, password_hash($newPassword, PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
}

function pw_request_row(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare(
        'SELECT r.*, u.username, u.full_name, u.role, u.is_active
         FROM password_request r JOIN app_user u ON u.user_id = r.user_id
         WHERE r.request_id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function pw_pending_own(PDO $pdo, int $userId): ?array {
    $st = $pdo->prepare(
        "SELECT r.*, u.username, u.full_name, u.role
         FROM password_request r JOIN app_user u ON u.user_id = r.user_id
         WHERE r.user_id = ? AND r.status = 'pending'
         ORDER BY r.request_id DESC LIMIT 1"
    );
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
}

function pw_map_request(array $r): array {
    $key = pw_role_key((string)$r['role']);
    return [
        'id'          => (int)$r['request_id'],
        'userId'      => (int)$r['user_id'],
        'fullName'    => (string)($r['full_name'] ?? ''),
        'username'    => (string)$r['username'],
        'role'        => $key,
        'roleLabel'   => pw_role_label($key),
        'mode'        => (string)$r['mode'],
        'requestedAt' => (string)$r['requested_at'],
    ];
}

function pw_waiting_text(string $roleKey): string {
    return $roleKey === 'manager'
        ? 'Your new password is waiting for the Owner to approve it.'
        : 'Your new password is waiting for the Manager or Owner to approve it.';
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
                    . 'on file yet (ask the Owner to add one on Staff & Access) or email is not set up on this server.']);
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
        if ($roleKey === 'owner') { // no approval needed
            pw_apply_password($pdo, $user, $newPassword);
            log_staff($pdo, (int)$user['user_id'], $name, (string)$user['username'], 'owner',
                (int)$user['user_id'], $name, (string)$user['username'], 'owner',
                'password_changed', 'Password reset with an email code (Owner needs no approval)');
            send_json(['ok' => true, 'status' => 'applied',
                'message' => 'Password changed. You can sign in with the new password now.']);
        }

        pw_create_request($pdo, $user, $newPassword, 'forgot');
        log_staff($pdo, (int)$user['user_id'], $name, (string)$user['username'], $roleKey,
            (int)$user['user_id'], $name, (string)$user['username'], $roleKey,
            'password_requested', 'Reset with an email code — waiting for approval');
        send_json(['ok' => true, 'status' => 'pending',
            'message' => 'Code confirmed. ' . pw_waiting_text($roleKey)]);
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
        if ($roleKey === 'owner') {
            pw_apply_password($pdo, $user, $newPassword);
            log_staff($pdo, $meId, $name, (string)$user['username'], 'owner',
                $meId, $name, (string)$user['username'], 'owner',
                'password_changed', 'Password changed (Owner needs no approval)');
            send_json(['ok' => true, 'status' => 'applied',
                'message' => 'Password changed. Use the new password next time you sign in.']);
        }

        $requestId = pw_create_request($pdo, $user, $newPassword, 'change');
        log_staff($pdo, $meId, $name, (string)$user['username'], $roleKey,
            $meId, $name, (string)$user['username'], $roleKey,
            'password_requested', 'Requested a new password — waiting for approval');
        send_json(['ok' => true, 'status' => 'pending', 'requestId' => $requestId,
            'message' => pw_waiting_text($roleKey)]);
    }

    /* ------------------------ 4. pending requests (list) ---------------------- */
    if ($method === 'GET' && $action === 'list') {
        $roleKey = require_login();
        $meId = current_user_id();

        $pending = [];
        if ($roleKey === 'owner' || $roleKey === 'manager') {
            $sql = "SELECT r.*, u.username, u.full_name, u.role, u.is_active
                    FROM password_request r JOIN app_user u ON u.user_id = r.user_id
                    WHERE r.status = 'pending'";
            $params = [];
            if ($roleKey === 'manager') { // a manager only approves cashiers
                $sql .= ' AND u.role = ?';
                $params[] = 'staff';
            }
            $sql .= ' ORDER BY r.request_id';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $pending = array_map('pw_map_request', $st->fetchAll());
        }

        $own = pw_pending_own($pdo, $meId);
        send_json(['ok' => true, 'data' => [
            'role'    => $roleKey,
            'pending' => $pending,
            'mine'    => $own ? pw_map_request($own) : null,
        ]]);
    }

    /* ------------------------- 5. approve / reject / cancel ------------------- */
    if ($method === 'POST' && in_array($action, ['approve', 'reject'], true)) {
        $roleKey = require_roles(['owner', 'manager'], 'approve password changes');
        $id = v_id($_GET['id'] ?? 0, 'password request');
        $row = pw_request_row($pdo, $id);
        if (!$row) fail('Password request not found.', 404);
        if ($row['status'] !== 'pending') fail('This request is no longer pending.', 409);

        $targetKey = pw_role_key((string)$row['role']);
        if ($targetKey === 'owner') fail('The Owner password does not need approval.', 403);
        if ($roleKey === 'manager' && $targetKey !== 'cashier') {
            fail('A Manager can only ' . $action . ' Cashier password requests.', 403);
        }
        if ((int)$row['user_id'] === current_user_id()) fail('You cannot decide your own request.', 403);

        $name = (string)($row['full_name'] ?? '');
        if ($action === 'approve') {
            $pdo->prepare('UPDATE app_user SET password_hash = ? WHERE user_id = ?')
                ->execute([(string)$row['new_password_hash'], (int)$row['user_id']]);
            clear_login_attempts($pdo, (string)$row['username']);
            $pdo->prepare("UPDATE password_request SET status = 'approved', decided_by = ?, decided_at = NOW() WHERE request_id = ?")
                ->execute([current_user_id(), $id]);
            log_staff($pdo, current_user_id(), (string)($_SESSION['full_name'] ?? ''), (string)($_SESSION['username'] ?? ''), $roleKey,
                (int)$row['user_id'], $name, (string)$row['username'], $targetKey,
                'password_approved', 'Approved a new password (' . $row['mode'] . ' request)');
            send_json(['ok' => true, 'message' => 'New password approved — ' . $name . ' can sign in with it now.']);
        }

        $pdo->prepare("UPDATE password_request SET status = 'rejected', decided_by = ?, decided_at = NOW() WHERE request_id = ?")
            ->execute([current_user_id(), $id]);
        log_staff($pdo, current_user_id(), (string)($_SESSION['full_name'] ?? ''), (string)($_SESSION['username'] ?? ''), $roleKey,
            (int)$row['user_id'], $name, (string)$row['username'], $targetKey,
            'password_rejected', 'Rejected a new password (' . $row['mode'] . ' request)');
        send_json(['ok' => true, 'message' => 'Request rejected. The old password still works.']);
    }

    if ($method === 'POST' && $action === 'cancel') {
        require_login();
        $id = v_id($_GET['id'] ?? 0, 'password request');
        $row = pw_request_row($pdo, $id);
        if (!$row) fail('Password request not found.', 404);
        if ((int)$row['user_id'] !== current_user_id()) fail('You can only cancel your own request.', 403);
        if ($row['status'] !== 'pending') fail('This request is no longer pending.', 409);
        $pdo->prepare("UPDATE password_request SET status = 'cancelled', decided_at = NOW()
            WHERE request_id = ? AND status = 'pending'")->execute([$id]);
        send_json(['ok' => true, 'message' => 'Request cancelled. Your current password stays.']);
    }

    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
