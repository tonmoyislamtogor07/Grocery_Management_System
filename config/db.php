<?php
// config/db.php — single PDO connection used by every API endpoint.

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'gms_db';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    if (strlen($raw) > 1048576) fail('Request body too large.', 413); // 1 MB cap
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function send_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $status = 400): void {
    send_json(['ok' => false, 'error' => $msg], $status);
}

/* ==========================================================================
   Input validation + sanitization. EVERY endpoint must pass untrusted input
   through these before touching the database:
   - control characters / HTML tags stripped, whitespace collapsed
   - names: letters + digits + limited punctuation, length-checked
   - phones: Bangladesh mobile — exactly 11 digits starting 01[3-9]
   - emails: FILTER_VALIDATE_EMAIL; money: finite number rounded to 2dp
   - dates: real calendar dates; ids: positive integers
   ========================================================================== */

function clean_text($v): string {
    if (is_array($v) || is_object($v) || $v === null) return '';
    $s = (string)$v;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s); // control chars
    $s = strip_tags($s);                                             // no HTML/JS
    return trim(preg_replace('/\s+/u', ' ', $s));                     // collapse spaces
}

// Generic name rule: 2+ chars, unicode letters/digits plus . , ' - ( ) & / % +
function v_name($v, string $field, int $min = 2, int $max = 200): string {
    if (is_array($v) || is_object($v) || $v === null) fail("$field is required.", 422);
    $raw = (string)$v;
    // reject outright if it ever contained tags / control chars (no silent rewrite)
    if (preg_match('/<[^>]*>|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)) {
        fail("$field contains invalid characters. Letters, digits, spaces and . , ' - ( ) & / % + are allowed.", 422);
    }
    $s = clean_text($v);
    $len = mb_strlen($s);
    if ($len < $min) fail("$field must be at least $min characters.", 422);
    if ($len > $max) fail("$field must be at most $max characters.", 422);
    if (!preg_match("/^[\p{L}\p{M}0-9 .,'()&\\/%+-]+$/u", $s)) {
        fail("$field contains invalid characters. Letters, digits, spaces and . , ' - ( ) & / % + are allowed.", 422);
    }
    return $s;
}

function v_username($v): string {
    $s = clean_text($v);
    if (!preg_match('/^[A-Za-z0-9._]{3,50}$/', $s)) fail('Invalid username format.', 422);
    return $s;
}

// Bangladesh mobile: strip spaces/dashes, then exactly 11 digits, 01[3-9]…
function v_phone($v, string $field = 'Phone', bool $required = false): ?string {
    $s = preg_replace('/[\s\-()]/', '', clean_text($v));
    if ($s === '') {
        if ($required) fail("$field is required.", 422);
        return null;
    }
    if (!preg_match('/^01[3-9]\d{8}$/', $s)) {
        fail("$field must be a valid 11-digit mobile number starting with 01 (e.g. 01712345678).", 422);
    }
    return $s;
}

function v_email($v, string $field = 'Email'): ?string {
    $s = clean_text($v);
    if ($s === '') return null;
    if (mb_strlen($s) > 100 || !filter_var($s, FILTER_VALIDATE_EMAIL)) {
        fail("$field must be a valid email address.", 422);
    }
    return $s;
}

function v_money($v, string $field, float $min = 0.0, bool $allowZero = true): float {
    if (is_array($v) || is_object($v) || $v === '' || $v === null || !is_numeric($v)) {
        fail("$field must be a number.", 422);
    }
    $f = round((float)$v, 2);
    if (!is_finite($f)) fail("$field is not a valid number.", 422);
    if ($f < $min || (!$allowZero && $f <= 0)) {
        fail($allowZero ? "$field cannot be negative." : "$field must be greater than zero.", 422);
    }
    if ($f > 9999999999.99) fail("$field is too large.", 422);
    return $f;
}

function v_qty($v, string $field, int $min = 1): int {
    if (is_array($v) || is_object($v) || $v === '' || $v === null || filter_var($v, FILTER_VALIDATE_INT) === false) {
        fail("$field must be a whole number.", 422);
    }
    $i = (int)$v;
    if ($i < $min) fail("$field must be at least $min.", 422);
    if ($i > 1000000) fail("$field is too large.", 422);
    return $i;
}

function v_date($v, string $field): string {
    $s = clean_text($v);
    $d = DateTime::createFromFormat('Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) fail("$field must be a valid date (YYYY-MM-DD).", 422);
    if ($s < '2000-01-01' || $s > '2100-12-31') fail("$field is out of range.", 422);
    return $s;
}

function v_datetime($v, string $field): string {
    $s = clean_text($v);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s)) {
        $d = new DateTime($s);
        return $d->format('Y-m-d H:i:s');
    }
    return v_date($v, $field) . ' 12:00:00';
}

function v_id($v, string $field = 'ID'): int {
    if (filter_var($v, FILTER_VALIDATE_INT) === false || (int)$v <= 0) {
        fail("Invalid $field.", 422);
    }
    return (int)$v;
}

function v_optional_date($v, string $field): ?string {
    $s = clean_text($v);
    if ($s === '') return null;
    return v_date($s, $field);
}

// Map a DB row -> the shape the UI (store.js) expects.
function map_category(array $r): array {
    return ['id' => (int)$r['category_id'], 'name' => $r['category_name'], 'description' => $r['description'] ?? ''];
}
function map_supplier(array $r): array {
    return ['id' => (int)$r['supplier_id'], 'name' => $r['supplier_name'], 'phone' => $r['phone'] ?? '', 'address' => $r['address'] ?? ''];
}
function map_customer(array $r): array {
    return ['id' => (int)$r['customer_id'], 'name' => $r['customer_name'], 'phone' => $r['phone'] ?? ''];
}
function map_product(array $r, array $supplierIds = []): array {
    $ids = array_values(array_unique(array_map('intval', $supplierIds)));
    return [
        'id'          => (int)$r['product_id'],
        'name'        => $r['product_name'],
        'categoryId'  => $r['category_id'] !== null ? (int)$r['category_id'] : null,
        // Option B: many-to-many sources. supplierId = first link (backward compat).
        'supplierIds' => $ids,
        'supplierId'  => count($ids) > 0 ? (int)$ids[0] : null,
        'price'       => (float)$r['selling_price'],
        'purchasePrice' => (float)($r['purchase_price'] ?? 0),
        'stock'       => (int)$r['stock_quantity'],
        'reorderLevel'=> (int)$r['minimum_stock'],
        'expiry'      => $r['expiry_date'] ? substr((string)$r['expiry_date'], 0, 10) : '',
    ];
}

// Allowed suppliers for one product (Option B junction table).
function product_supplier_ids(PDO $pdo, int $productId): array {
    try {
        $st = $pdo->prepare('SELECT supplier_id FROM product_suppliers WHERE product_id = ? ORDER BY supplier_id');
        $st->execute([$productId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

// Allowed suppliers for many products in one query (avoids N+1 on list).
function product_suppliers_map(PDO $pdo, array $productIds): array {
    $map = [];
    $ids = array_values(array_unique(array_map('intval', $productIds)));
    if (count($ids) === 0) return $map;
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT product_id, supplier_id FROM product_suppliers WHERE product_id IN ($ph) ORDER BY product_id, supplier_id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            $pid = (int)$row['product_id'];
            $map[$pid][] = (int)$row['supplier_id'];
        }
    } catch (Throwable $e) {}
    return $map;
}
function map_expense(array $r): array {
    return [
        'id' => (int)$r['expense_id'], 'category' => $r['expense_type'] ?? 'General',
        'description' => $r['description'] ?? '', 'amount' => (float)$r['amount'],
        'date' => $r['expense_date'] ? substr((string)$r['expense_date'], 0, 10) : '',
    ];
}
