<?php
// seed-demo.php — OPTIONAL demo-history generator for the Insights dashboard.
// Creates 24 months (2024-11 … 2026-10) of realistic purchase, sale,
// due collections so every Insights chart has full data.
//
// Safety:
//  - Reads LIVE stock first; every month purchase cover that month's
//    planned sale, so stock can never go negative (asserted at the end).
//  - Lots follow the same FEFO order as the app (expiry first, oldest lot
//    first); product<->supplier links are learned like real purchase.
//  - One transaction: any error rolls everything back.
//  - Re-runnable guard: refuses to run twice (checks old sale dates).
//
// Usage:  php seed-demo.php          (writes for real)
//         php seed-demo.php --dry    (preview only, rolls back)
$migrate = in_array('--dry', $argv ?? []);
$DRY = $migrate;

$host = getenv('DB_HOST') ?: '127.0.0.1';
$name = getenv('DB_NAME') ?: 'gms_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

mt_srand(20261007);
$r = function ($a, $b) { return mt_rand($a, $b); };
$pick = function (array $arr) { return $arr[mt_rand(0, count($arr) - 1)]; };

// ---- re-run guard: demo months must not already exist -----------------------
$hit = $pdo->query("SELECT COUNT(*) FROM sale WHERE sale_date < '2026-09-01'")->fetchColumn();
if ($hit > 0) { echo "STOP: {$hit} pre-September sale(s) already exist — demo was probably seeded. Delete them first to re-run.\n"; exit(1); }

// ---- live catalog ------------------------------------------------------------
$product = []; // pid => ['price'=>f]
foreach ($pdo->query('SELECT product_id, selling_price FROM product ORDER BY product_id')->fetchAll() as $p) {
    $product[(int)$p['product_id']] = ['price' => (float)$p['selling_price']];
}
if (count($product) < 3) { echo "STOP: need product in catalog first.\n"; exit(1); }
$supIds = $pdo->query('SELECT supplier_id FROM supplier ORDER BY supplier_id')->fetchAll(PDO::FETCH_COLUMN);
$supIds = array_map('intval', $supIds);

// area customer: assign areas to existing NULL-area regulars, add 4 more
$pdo->exec("UPDATE customer SET area='Gollamari' WHERE customer_id=1 AND area IS NULL");
$pdo->exec("UPDATE customer SET area='Sonadanga' WHERE customer_name='Tonmoy Ahmed' AND area IS NULL");
$pdo->exec("UPDATE customer SET area='Khalishpur' WHERE customer_name='Saeed' AND area IS NULL");
$areas = ['Sonadanga', 'Khalishpur', 'Tutpara', 'Gollamari'];
$newCust = [
    ['Abdul Malek', '01755500113', 'Sonadanga'], ['Nasrin Akter', '01755500124', 'Khalishpur'],
    ['Jamal Hossain', '01755500135', 'Tutpara'], ['Salma Khatun', '01755500146', 'Gollamari'],
];
$stC = $pdo->prepare('SELECT customer_id FROM customer WHERE customer_name = ? LIMIT 1');
$stCi = $pdo->prepare('INSERT INTO customer (customer_name, phone, area) VALUES (?, ?, ?)');
foreach ($newCust as $nc) {
    $stC->execute([$nc[0]]);
    if (!$stC->fetch()) $stCi->execute($nc);
}
$custIds = array_map('intval', $pdo->query('SELECT customer_id FROM customer ORDER BY customer_id')->fetchAll(PDO::FETCH_COLUMN));

// ---- in-memory stock + lots (FEFO replica) ------------------------------------
$stock = []; // pid => qty
foreach ($pdo->query('SELECT product_id, COALESCE(SUM(qty_left),0) s FROM stock_lot GROUP BY product_id')->fetchAll() as $row) {
    $stock[(int)$row['product_id']] = (int)$row['s'];
}
foreach ($product as $pid => $_) if (!isset($stock[$pid])) $stock[$pid] = 0;
$lots = $pdo->query('SELECT lot_id, product_id, qty_left, unit_cost, expiry_date FROM stock_lot ORDER BY lot_id')->fetchAll();

$fefo = function ($pid) use (&$lots) {
    $out = [];
    foreach ($lots as $l) {
        if ((int)$l['product_id'] === $pid && (int)$l['qty_left'] > 0) $out[] = $l;
    }
    usort($out, function ($a, $b) {
        $an = $a['expiry_date'] === null; $bn = $b['expiry_date'] === null;
        if ($an !== $bn) return $an ? 1 : -1;
        if ($a['expiry_date'] !== $b['expiry_date']) return strcmp((string)$a['expiry_date'], (string)$b['expiry_date']);
        return (int)$a['lot_id'] - (int)$b['lot_id'];
    });
    return $out;
};
// returns [lot_id => qty] and updates in-memory lots
$consume = function ($pid, $qty) use (&$lots, $fefo) {
    $need = $qty; $alloc = [];
    foreach ($fefo($pid) as $l) {
        if ($need <= 0) break;
        $take = min($need, (int)$l['qty_left']);
        foreach ($lots as &$ll) {
            if ((int)$ll['lot_id'] === (int)$l['lot_id']) { $ll['qty_left'] = (int)$ll['qty_left'] - $take; break; }
        }
        unset($ll);
        $alloc[(int)$l['lot_id']] = $take;
        $need -= $take;
    }
    if ($need > 0) throw new Exception("would oversell product $pid (short $need)");
    return $alloc;
};

// ---- demand model: base monthly qty per product (cheap staples sell more) ----
$base = [];
foreach ($product as $pid => $p) {
    $pr = $p['price'];
    $base[$pid] = $pr <= 50 ? $r(14, 30) : ($pr <= 120 ? $r(6, 14) : ($pr <= 600 ? $r(2, 6) : $r(1, 3)));
}
$months = [];
for ($y = 2024; $y <= 2026; $y++) {
    for ($m = 1; $m <= 12; $m++) {
        if ($y === 2024 && $m < 11) continue;
        if ($y === 2026 && $m > 10) continue;
        $months[] = sprintf('%04d-%02d', $y, $m);
    }
}
// festive bumps (Eid/Ramadan trading): Mar-2025, Jun-2025(qurbani), Feb-Mar-2026
$bump = ['2025-03' => 1.35, '2025-06' => 1.25, '2026-02' => 1.3, '2026-03' => 1.15, '2025-12' => 1.15];

$pdo->beginTransaction();
$stPur = $pdo->prepare('INSERT INTO purchase (supplier_id, purchase_date) VALUES (?, ?)');
$stPd = $pdo->prepare('INSERT INTO purchase_detail (purchase_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
$stLot = $pdo->prepare('INSERT INTO stock_lot (product_id, purchase_id, purchase_detail_id, qty_bought, qty_left, unit_cost, expiry_date, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stLink = $pdo->prepare('INSERT IGNORE INTO product_supplier (product_id, supplier_id) VALUES (?, ?)');
$stSale = $pdo->prepare('INSERT INTO sale (customer_id, discount, sale_date) VALUES (?, 0, ?)');
$stSd = $pdo->prepare('INSERT INTO sale_detail (sale_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
$stSl = $pdo->prepare('INSERT INTO sale_lot (sale_detail_id, lot_id, quantity) VALUES (?, ?, ?)');
$stDec = $pdo->prepare('UPDATE stock_lot SET qty_left = qty_left - ? WHERE lot_id = ?');
$stPay = $pdo->prepare('INSERT INTO sale_payment (sale_id, amount, paid_at) VALUES (?, ?, ?)');

$nSales = 0; $nPur = 0; $nPay = 0; $revTotal = 0; $mi = 0;
$monthRev = [];
foreach ($months as $ym) {
    $mi++;
    [$yy, $mm] = array_map('intval', explode('-', $ym));
    $isCur = ($yy === 2026 && $mm === 10);
    $maxDay = $isCur ? 7 : 28;
    $season = 1 + 0.15 * sin(($mm - 1) / 12 * 2 * M_PI);
    $growth = 1 + 0.02 * $mi;
    $mult = $season * $growth * ($bump[$ym] ?? 1.0);

    // planned sale qty per product this month
    $plan = [];
    foreach ($product as $pid => $p) {
        $q = (int)round($base[$pid] * $mult * $r(80, 125) / 100);
        if ($q > 0) $plan[$pid] = $q;
    }
    // purchase FIRST (cover plan + small buffer), grouped per supplier
    $bySup = [];
    $pi = 0;
    foreach ($plan as $pid => $q) {
        $sup = $supIds[($mi + $pi) % count($supIds)];
        $pi++;
        $buy = $q + $r(0, max(1, (int)round($q * 0.1)));
        $bySup[$sup][] = ['pid' => $pid, 'qty' => $buy];
    }
    foreach ($bySup as $sup => $lines) {
        $pday = $r(1, min(4, $maxDay));
        $pdt = sprintf('%s-%02d 10:00:00', $ym, $pday);
        $stPur->execute([$sup, $pdt]);
        $purId = (int)$pdo->lastInsertId();
        $nPur++;
        foreach ($lines as $ln) {
            $pid = $ln['pid']; $qty = $ln['qty'];
            $cost = max(1, round($product[$pid]['price'] * $r(82, 93) / 100, 2));
            $stPd->execute([$purId, $pid, $qty, $cost]);
            $did = (int)$pdo->lastInsertId();
            $expDays = ($pid === 4) ? $r(45, 120) : $r(200, 540);
            $exp = date('Y-m-d', strtotime("$ym-15 +$expDays days"));
            $stLot->execute([$pid, $purId, $did, $qty, $qty, $cost, $exp, $pdt]);
            $lid = (int)$pdo->lastInsertId(); // real id (AUTO_INCREMENT never matches predictions after rollbacks)
            $lots[] = ['lot_id' => $lid, 'product_id' => $pid, 'qty_left' => $qty, 'unit_cost' => $cost, 'expiry_date' => $exp];
            $stock[$pid] += $qty;
            $stLink->execute([$pid, $sup]);
        }
    }
    // sale: split planned qtys into bills across the month
    $remain = $plan;
    $bills = $r(9, 14);
    for ($b = 0; $b < $bills && array_sum($remain) > 0; $b++) {
        $keys = array_keys(array_filter($remain, function ($q) { return $q > 0; }));
        shuffle($keys);
        $takeN = min(count($keys), $r(1, 3));
        $items = [];
        for ($i = 0; $i < $takeN; $i++) {
            $pid = $keys[$i];
            $cap = $product[$pid]['price'] <= 100 ? 12 : ($product[$pid]['price'] <= 600 ? 4 : 2);
            $q = min($remain[$pid], $r(1, $cap));
            $remain[$pid] -= $q;
            $items[] = ['pid' => $pid, 'qty' => $q, 'price' => $product[$pid]['price']];
        }
        $cid = (mt_rand(1, 100) <= 50) ? null : $pick($custIds);
        $day = $r(3, $maxDay);
        $sdt = sprintf('%s-%02d %02d:%02d:00', $ym, $day, $r(9, 20), $r(0, 59));
        $stSale->execute([$cid, $sdt]);
        $sid = (int)$pdo->lastInsertId();
        $nSales++;
        $total = 0;
        foreach ($items as $it) {
            $total += $it['qty'] * $it['price'];
            $stSd->execute([$sid, $it['pid'], $it['qty'], $it['price']]);
            $sdid = (int)$pdo->lastInsertId();
            foreach ($consume($it['pid'], $it['qty']) as $lotId => $take) {
                $stSl->execute([$sdid, $lotId, $take]);
                $stDec->execute([$take, $lotId]); // persist FEFO consumption
            }
            $stock[$it['pid']] -= $it['qty'];
        }
        $total = round($total, 2);
        $revTotal += $total;
        $monthRev[$ym] = ($monthRev[$ym] ?? 0) + $total;
        // payments: usually full, sometimes partial + later collection
        $roll = mt_rand(1, 100);
        if ($roll <= 72) {
            $stPay->execute([$sid, $total, $sdt]);
            $nPay++;
        } else {
            $first = round($total * $r(55, 85) / 100, 2);
            $stPay->execute([$sid, $first, $sdt]);
            $nPay++;
            if (mt_rand(1, 100) <= 65) {
                $rest = round($total - $first, 2);
                $amt = ($rest <= 0) ? 0 : round($rest * $r(50, 100) / 100, 2);
                if ($amt > 0) {
                    $cd = date('Y-m-d H:i:00', strtotime($sdt . ' +' . $r(4, 20) . ' days'));
                    if ($cd > '2026-10-07 23:59:59') $cd = '2026-10-07 18:00:00';
                    $stPay->execute([$sid, min($amt, $rest), $cd]);
                    $nPay++;
                }
            }
        }
    }
}
// final stock assertion (in-memory vs DB lots agree, nothing negative)
foreach ($stock as $pid => $q) {
    if ($q < 0) throw new Exception("negative stock product $pid");
}
if ($DRY) { $pdo->rollBack(); echo "[DRY RUN — rolled back]\n"; }
else { $pdo->commit(); echo "[COMMITTED]\n"; }
echo 'months=' . count($months) . " sale=$nSales purchase=$nPur payments=$nPay revenue=" . round($revTotal, 2) . "\n";
echo "stock after:\n";
foreach ($stock as $pid => $q) echo "  product $pid => $q\n";
