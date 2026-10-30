<?php
// api/users.php — staff accounts (Owner / Manager / Cashier).
//
// Permission matrix (enforced HERE, server-side — the UI only hides buttons):
//   Owner   : hire Cashier + Manager, edit/deactivate anyone, permanent delete,
//             change a role.
//   Manager : hire Cashier ONLY, edit/deactivate Cashier ONLY, never delete,
//             never change a role or touch an Owner/Manager account.
//   Nobody  : creates another Owner account, deletes/deactivates themselves,
//             or removes the last active Owner.
// A user with sale/purchase/expense history can never be deleted (FK RESTRICT):
// deactivate instead so old bills keep the real name (audit trail).
// Every change is written to staff_log: who did it, to whom, and when.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

const ROLE_UI_LABEL = ['owner' => 'Owner', 'manager' => 'Manager', 'cashier' => 'Cashier'];
const ROLE_DB        = ['owner' => 'admin', 'manager' => 'manager', 'cashier' => 'staff'];
const ROLE_KEY       = ['admin' => 'owner', 'manager' => 'manager', 'staff' => 'cashier'];

function staff_role_key(string $dbRole): string {
    return ROLE_KEY[$dbRole] ?? $dbRole;
}
function staff_role_label(string $key): string {
    return ROLE_UI_LABEL[$key] ?? ucfirst($key);
}

/* staff_log_ready() + log_staff() live in config/db.php so the password
   approval code writes to the same audit trail. */

// v_password() lives in config/db.php (shared with api/password.php).

function active_owner_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM app_user WHERE role = 'admin' AND is_active = 1")->fetchColumn();
}

function staff_activity_counts(PDO $pdo, array $ids): array {
    $out = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) === 0) return $out;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (['sale', 'purchase', 'expense'] as $table) {
        $st = $pdo->prepare("SELECT user_id, COUNT(*) c FROM `$table` WHERE user_id IN ($ph) GROUP BY user_id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            $uid = (int)$row['user_id'];
            $out[$uid] = ($out[$uid] ?? 0) + (int)$row['c'];
        }
    }
    return $out;
}

function map_staff(array $r, int $activity = 0, int $lockMinutes = 0): array {
    $key = staff_role_key((string)$r['role']);
    return [
        'id'          => (int)$r['user_id'],
        'username'    => (string)$r['username'],
        'fullName'    => (string)($r['full_name'] ?? ''),
        'role'        => $key,
        'roleLabel'   => staff_role_label($key),
        'email'       => (string)($r['email'] ?? ''),
        'isActive'    => (int)$r['is_active'] === 1,
        'activity'    => $activity,
        'locked'      => $lockMinutes > 0,
        'lockMinutes' => $lockMinutes,
        'createdAt'   => $r['created_at'] ? substr((string)$r['created_at'], 0, 10) : '',
    ];
}

/* Username => minutes left on its login lock. Only accounts that reached
   MAX_FAILED_ATTEMPTS inside the lock window are returned. */
function login_lock_map(PDO $pdo, array $usernames): array {
    login_attempt_ready($pdo);
    $names = array_values(array_unique(array_filter(array_map('strval', $usernames), 'strlen')));
    if (count($names) === 0) return [];
    $out = [];
    try {
        $ph = implode(',', array_fill(0, count($names), '?'));
        $st = $pdo->prepare('SELECT username, COUNT(*) c, MAX(attempted_at) last_at
            FROM login_attempt
            WHERE success = 0
              AND attempted_at > (NOW() - INTERVAL ' . LOCK_MINUTES . ' MINUTE)
              AND username IN (' . $ph . ')
            GROUP BY username');
        $st->execute($names);
        foreach ($st->fetchAll() as $r) {
            if ((int)$r['c'] < MAX_FAILED_ATTEMPTS) continue;
            $mins = (int)ceil((strtotime((string)$r['last_at']) + LOCK_MINUTES * 60 - time()) / 60);
            if ($mins > 0) $out[(string)$r['username']] = $mins;
        }
    } catch (Throwable $e) {}
    return $out;
}

function map_log(array $r): array {
    return [
        'id'             => (int)$r['log_id'],
        'at'             => (string)$r['created_at'],
        'actorName'      => (string)($r['actor_name'] ?? ''),
        'actorUsername'  => (string)($r['actor_username'] ?? ''),
        'actorRole'      => (string)($r['actor_role'] ?? ''),
        'targetName'     => (string)($r['target_name'] ?? ''),
        'targetUsername' => (string)($r['target_username'] ?? ''),
        'targetRole'     => (string)($r['target_role'] ?? ''),
        'action'         => (string)$r['action'],
        'details'        => (string)($r['details'] ?? ''),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
try {
    $pdo  = db();
    password_schema_ready($pdo); // app_user.email + password tables
    $role = require_roles(['owner', 'manager'], 'manage staff accounts');
    $meId       = current_user_id();
    $meName     = (string)($_SESSION['full_name'] ?? '');
    $meUsername = (string)($_SESSION['username'] ?? '');
    staff_log_ready($pdo);

    /* ------------------------------ READ ------------------------------ */
    if ($method === 'GET') {
        if (($_GET['action'] ?? '') === 'log') {
            $sql = 'SELECT * FROM staff_log';
            $params = [];
            // A Manager only sees changes about Cashier accounts (or made by them).
            if ($role === 'manager') {
                $sql .= ' WHERE target_role = ? OR actor_user_id = ?';
                $params = ['cashier', $meId];
            }
            $sql .= ' ORDER BY log_id DESC LIMIT 100';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            send_json(['ok' => true, 'data' => array_map('map_log', $st->fetchAll())]);
        }

        $sql = 'SELECT * FROM app_user';
        $params = [];
        if ($role === 'manager') { // managers do not see Owner/Manager accounts
            $sql .= ' WHERE role = ?';
            $params = ['staff'];
        }
        $sql .= " ORDER BY FIELD(role, 'admin', 'manager', 'staff'), is_active DESC, username";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        $counts = staff_activity_counts($pdo, array_column($rows, 'user_id'));
        $locks = login_lock_map($pdo, array_column($rows, 'username'));
        $data = array_map(function ($r) use ($counts, $locks) {
            return map_staff(
                $r,
                (int)($counts[(int)$r['user_id']] ?? 0),
                (int)($locks[(string)$r['username']] ?? 0)
            );
        }, $rows);
        send_json(['ok' => true, 'data' => $data, 'me' => ['id' => $meId, 'role' => $role]]);
    }

    /* ------------------------- UNLOCK a locked login ----------------------- */
    if ($method === 'POST' && ($_GET['action'] ?? '') === 'unlock') {
        $id = v_id($_GET['id'] ?? 0, 'staff account');
        $st = $pdo->prepare('SELECT * FROM app_user WHERE user_id = ?');
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) fail('Staff account not found.', 404);
        $targetKey = staff_role_key((string)$target['role']);
        if ($role === 'manager' && $targetKey !== 'cashier') {
            fail('A Manager can only unlock Cashier accounts.', 403);
        }
        clear_login_attempts($pdo, (string)$target['username']);
        log_staff($pdo, $meId, $meName, $meUsername, $role, $id,
            (string)($target['full_name'] ?? ''), (string)$target['username'], $targetKey,
            'unlocked', 'Login unlocked after too many wrong passwords');
        $counts = staff_activity_counts($pdo, [$id]);
        send_json(['ok' => true, 'data' => map_staff($target, (int)($counts[$id] ?? 0), 0)]);
    }

    /* --------------------------- CREATE (hire) --------------------------- */
    if ($method === 'POST') {
        $in = json_input();
        $username = v_username($in['username'] ?? '');
        $fullName = v_name($in['fullName'] ?? '', 'Full name', 2, 150);
        $password = v_password($in['password'] ?? '', true);
        $email = v_email($in['email'] ?? '', 'Email'); // reset codes go here
        $wantKey  = strtolower(trim((string)($in['role'] ?? '')));

        if (!isset(ROLE_DB[$wantKey])) fail('Choose a role: Cashier or Manager.', 422);
        if ($wantKey === 'owner') fail('There is one Owner account and no new Owner can be created here.', 403);
        // Rule 1: a Manager may only create Cashiers (no privilege escalation).
        if ($role === 'manager' && $wantKey !== 'cashier') {
            fail('A Manager can only create Cashier accounts.', 403);
        }

        $st = $pdo->prepare('SELECT 1 FROM app_user WHERE username = ?');
        $st->execute([$username]);
        if ($st->fetch()) fail('That username is already taken. Pick another one.', 409);

        $pdo->prepare('INSERT INTO app_user (username, password_hash, full_name, email, role, is_active) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email, ROLE_DB[$wantKey]]);
        $id = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('SELECT * FROM app_user WHERE user_id = ?');
        $st->execute([$id]);
        $row = $st->fetch();

        log_staff($pdo, $meId, $meName, $meUsername, $role, $id, $fullName, $username, $wantKey,
            'created', 'Hired as ' . staff_role_label($wantKey) . ' (@' . $username . ')'
                . ($email ? ' · reset email ' . $email : ''));

        send_json(['ok' => true, 'data' => map_staff($row)], 201);
    }

    /* ------------------------------ UPDATE ------------------------------ */
    if ($method === 'PUT') {
        $id = v_id($_GET['id'] ?? 0, 'staff account');
        $st = $pdo->prepare('SELECT * FROM app_user WHERE user_id = ?');
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) fail('Staff account not found.', 404);

        $targetKey = staff_role_key((string)$target['role']);
        // Rule 1: a Manager may only touch Cashier accounts.
        if ($role === 'manager' && $targetKey !== 'cashier') {
            fail('A Manager can only edit or deactivate Cashier accounts.', 403);
        }

        $in = json_input();
        $fullName = v_name($in['fullName'] ?? '', 'Full name', 2, 150);
        // Email receives password-reset codes. Optional, but reset needs it.
        // Only touched when the request actually carries the field, so a
        // request that just deactivates an account keeps its email.
        $email = array_key_exists('email', $in)
            ? v_email($in['email'], 'Email')
            : ((string)($target['email'] ?? '') !== '' ? (string)$target['email'] : null);

        $wantKey = $targetKey;
        if (isset($in['role']) && trim((string)$in['role']) !== '') {
            $wantKey = strtolower(trim((string)$in['role']));
            if (!isset(ROLE_DB[$wantKey])) fail('Choose a role: Cashier or Manager.', 422);
        }
        $roleChanged = $wantKey !== $targetKey;
        if ($roleChanged) {
            // Rule 3: role changes belong to the Owner only.
            if ($role !== 'owner') fail('Only the Owner can change an account role.', 403);
            if ($wantKey === 'owner') fail('An account cannot be promoted to Owner.', 403);
            if ($id === $meId) fail('You cannot change your own role.', 403);
            if ($targetKey === 'owner' && active_owner_count($pdo) <= 1) {
                fail('The shop must keep at least one active Owner.', 409);
            }
        }

        $wasActive = (int)$target['is_active'] === 1;
        $isActive  = $wasActive;
        if (array_key_exists('isActive', $in)) $isActive = filter_var($in['isActive'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $wasActive;
        if (!$isActive && $wasActive) {
            if ($id === $meId) fail('You cannot deactivate your own account.', 403);
            if ($targetKey === 'owner' && active_owner_count($pdo) <= 1) {
                fail('The shop must keep at least one active Owner.', 409);
            }
        }

        $password = v_password($in['password'] ?? '', false);

        $oldEmail = (string)($target['email'] ?? '');
        $newEmail = (string)($email ?? '');

        $changes = [];
        if ($fullName !== (string)($target['full_name'] ?? '')) $changes[] = 'Name updated';
        if ($newEmail !== $oldEmail) $changes[] = $newEmail === '' ? 'Email removed' : 'Email updated';
        if ($roleChanged) $changes[] = 'Role ' . staff_role_label($targetKey) . ' → ' . staff_role_label($wantKey);
        if ($isActive !== $wasActive) $changes[] = $isActive ? 'Account reactivated' : 'Account deactivated';
        if ($password !== null) $changes[] = 'Password reset';

        $sql = 'UPDATE app_user SET full_name = ?, email = ?, role = ?, is_active = ?';
        $args = [$fullName, $email, ROLE_DB[$wantKey], $isActive ? 1 : 0];
        if ($password !== null) {
            $sql .= ', password_hash = ?';
            $args[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE user_id = ?';
        $args[] = $id;
        $pdo->prepare($sql)->execute($args);

        $action = ($isActive !== $wasActive)
            ? ($isActive ? 'activated' : 'deactivated')
            : ($roleChanged ? 'role_changed' : (($password !== null && count($changes) === 1) ? 'password_reset' : 'updated'));

        // A new password also clears a lock: the old guesses no longer apply.
        if ($password !== null) clear_login_attempts($pdo, (string)$target['username']);

        log_staff($pdo, $meId, $meName, $meUsername, $role, $id, $fullName, (string)$target['username'], $wantKey,
            $action, count($changes) ? implode(' · ', $changes) : 'No changes');

        $st = $pdo->prepare('SELECT * FROM app_user WHERE user_id = ?');
        $st->execute([$id]);
        $counts = staff_activity_counts($pdo, [$id]);
        $locks = login_lock_map($pdo, [(string)$target['username']]);
        send_json(['ok' => true, 'data' => map_staff(
            $st->fetch(),
            (int)($counts[$id] ?? 0),
            (int)($locks[(string)$target['username']] ?? 0)
        )]);
    }

    /* ------------------------- DELETE (Owner only) ----------------------- */
    if ($method === 'DELETE') {
        if ($role !== 'owner') fail('Only the Owner can permanently delete an account. Deactivate it instead.', 403);
        $id = v_id($_GET['id'] ?? 0, 'staff account');
        $st = $pdo->prepare('SELECT * FROM app_user WHERE user_id = ?');
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) fail('Staff account not found.', 404);
        if ($id === $meId) fail('You cannot delete your own account.', 403);

        $targetKey = staff_role_key((string)$target['role']);
        if ($targetKey === 'owner') fail('The Owner account cannot be deleted.', 409);
        if (!empty($target['is_active']) && $targetKey === 'admin' && active_owner_count($pdo) <= 1) {
            fail('The shop must keep at least one active Owner.', 409);
        }

        // Rule 2: keep history intact — accounts with records are deactivated, not deleted.
        $counts = staff_activity_counts($pdo, [$id]);
        $activity = (int)($counts[$id] ?? 0);
        if ($activity > 0) {
            fail('This account recorded ' . $activity . ' sale/purchase/expense record(s), so it cannot be deleted — deactivate it instead to keep the audit trail.', 409);
        }

        $pdo->prepare('DELETE FROM app_user WHERE user_id = ?')->execute([$id]);
        log_staff($pdo, $meId, $meName, $meUsername, $role, null, (string)($target['full_name'] ?? ''), (string)$target['username'],
            $targetKey, 'deleted', 'Account deleted permanently (no history)');
        send_json(['ok' => true]);
    }

    fail('Method not allowed.', 405);
} catch (Throwable $e) {
    // 23000 = duplicate key (race on the username unique index).
    if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
        fail('That username is already taken. Pick another one.', 409);
    }
    fail('Database error: ' . $e->getMessage(), 500);
}
