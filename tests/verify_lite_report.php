<?php

declare(strict_types=1);

// The shop's Reports for the lite till (app/Services/LiteReport.php): exact, hand-checked numbers for the app's own rules,
// and a real database run proving "the newest version wins", deleted records are left out and the range filters work.
// Needs MySQL for the database part (DB_HOST / DB_PORT / DB_USER / DB_PASS like the rest of the platform; on a local
// XAMPP that is root with no password); the throwaway database is dropped afterwards.
//
//   php tests/verify_lite_report.php

require __DIR__ . '/../app/Services/LiteReport.php';

use Platform\Services\LiteReport;

$failures = 0;
$checks = 0;
function check(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}
function same(mixed $actual, mixed $expected, string $message): void
{
    check($actual === $expected, $message . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

const FROM = '2026-11-12T00:00:00.000Z';
const TO = '2026-11-13T00:00:00.000Z';

function sale(string $id, array $extra = []): array
{
    return array_merge([
        'id' => $id, 'createdAt' => '2026-11-12T10:00:00.000000Z', 'updatedAt' => '2026-11-12T10:00:00.000000Z', 'deletedAt' => null,
        'saleNumber' => 'S-' . $id, 'userId' => 'u1', 'saleType' => 'retail', 'paymentMethod' => 'cash',
        'subtotalCents' => 10000, 'discountCents' => 0, 'totalCents' => 10000, 'status' => 'paid', 'cashReceivedCents' => null,
    ], $extra);
}
function item(string $saleId, string $name, int $qty, int $cost, string $id = '', string $createdAt = '2026-11-12T10:00:00.000000Z'): array
{
    return ['id' => $id !== '' ? $id : $saleId . '-' . $name, 'saleId' => $saleId, 'itemName' => $name, 'quantity' => $qty, 'costPriceCents' => $cost, 'createdAt' => $createdAt, 'deletedAt' => null];
}
function agg(array $sales, array $items = [], array $expenses = [], array $users = ['u1' => 'Wanjiru'], string $from = FROM, string $to = TO, string $fromDate = '2026-11-12', string $toDate = '2026-11-13'): array
{
    return LiteReport::aggregate($sales, $items, $expenses, $users, $from, $to, $fromDate, $toDate);
}

// ---- the numbers ------------------------------------------------------------------------------------------------
$r = agg(['a' => sale('a')], ['a' => [item('a', 'Soda', 2, 3000), item('a', 'Bread', 1, 1500)]]);
same($r['salesTotalCents'], 10000, 'one cash sale: total');
same($r['transactionCount'], 1, 'one cash sale: count');
same($r['grossProfitCents'], 10000 - (2 * 3000 + 1500), 'gross profit is the total minus the cost of the items');
same($r['cashReceivedCents'], 10000, 'a cash sale is all cash');
same($r['mpesaCents'], 0, 'a cash sale is no M-Pesa');
same($r['sales'][0]['itemNames'], 'Soda x2, Bread x1', 'item names with quantities, in the order they were rung up');
same($r['sales'][0]['cashierName'], 'Wanjiru', 'cashier name');
same($r['sales'][0]['status'], 'paid', 'only paid sales are listed');

// Items are named in the order they were rung up, whatever order their records arrived in.
$r = agg(['a' => sale('a')], ['a' => [item('a', 'Second', 1, 100, 'i2', '2026-11-12T10:00:02.000000Z'), item('a', 'First', 1, 100, 'i1', '2026-11-12T10:00:01.000000Z')]]);
same($r['sales'][0]['itemNames'], 'First x1, Second x1', 'items are named by when they were rung up');

// A discount is taken off the sale, so it is not counted a second time in the profit.
$r = agg(['a' => sale('a', ['subtotalCents' => 10000, 'discountCents' => 1500, 'totalCents' => 8500])], ['a' => [item('a', 'Soda', 2, 3000)]]);
same($r['grossProfitCents'], 8500 - 6000, 'gross profit uses the total AFTER the discount');
same($r['salesTotalCents'], 8500, 'the total is after the discount');

// Paying: Paystack/M-Pesa is M-Pesa; a split sale has its cash part first; manual M-Pesa without a cash figure is all M-Pesa.
$r = agg([
    'p' => sale('p', ['paymentMethod' => 'paystack', 'totalCents' => 12000]),
    'q' => sale('q', ['paymentMethod' => 'paystack', 'totalCents' => 10000, 'cashReceivedCents' => 4000]),
    'm' => sale('m', ['paymentMethod' => 'mpesa_manual', 'totalCents' => 5000]),
    'c' => sale('c', ['paymentMethod' => 'cash', 'totalCents' => 7000, 'cashReceivedCents' => 10000]),
]);
same($r['mpesaCents'], 12000 + 6000 + 5000, 'M-Pesa: full Paystack + the card part of a split + manual M-Pesa');
same($r['cashReceivedCents'], 4000 + 7000, 'cash: the cash part of the split + the cash sale (its TOTAL, not what was handed over)');
same($r['salesTotalCents'], 12000 + 10000 + 5000 + 7000, 'all four count');
same($r['cashReceivedCents'] + $r['mpesaCents'], $r['salesTotalCents'], 'cash + M-Pesa add up to the total');

// An unknown payment method counts in the total but in neither bucket.
$r = agg(['x' => sale('x', ['paymentMethod' => 'barter', 'totalCents' => 3000])]);
same([$r['salesTotalCents'], $r['cashReceivedCents'], $r['mpesaCents']], [3000, 0, 0], 'an unknown method is in the total only');

// A sale with no items: no cost, no names.
$r = agg(['a' => sale('a')]);
same($r['grossProfitCents'], 10000, 'no items: no cost');
same($r['sales'][0]['itemNames'], null, 'no items: no names');

// ---- what counts ------------------------------------------------------------------------------------------------
$r = agg([
    'in1' => sale('in1', ['createdAt' => '2026-11-12T00:00:00.000Z']),
    'in2' => sale('in2', ['createdAt' => '2026-11-12T23:59:59.999999Z']),
    'before' => sale('before', ['createdAt' => '2026-11-11T23:59:59.999999Z']),
    'after' => sale('after', ['createdAt' => '2026-11-13T00:00:00.000Z']),
]);
same($r['transactionCount'], 2, 'the start of the range counts, the end does not');
same(array_column($r['sales'], 'id'), ['in2', 'in1'], 'newest first');

foreach (['pending', 'cancelled', 'refused', ''] as $status) {
    $r = agg(['n' => sale('n', ['status' => $status])]);
    same($r['transactionCount'], 0, "a $status sale is not a paid sale");
}

$r = agg(['a' => sale('a', ['userId' => 'ghost'])]);
same($r['sales'][0]['cashierName'], 'Unknown', 'a sale by someone not in the list still counts, as Unknown');
same($r['salesTotalCents'], 10000, '... and its money is counted');

// ---- expenses ---------------------------------------------------------------------------------------------------
function expense(string $id, string $date, int $amount, array $extra = []): array
{
    return array_merge(['id' => $id, 'expenseDate' => $date, 'title' => 'T-' . $id, 'amountCents' => $amount, 'note' => null, 'userId' => 'u1', 'createdAt' => '2026-11-12T08:00:00.000000Z'], $extra);
}
$r = agg(['a' => sale('a', ['totalCents' => 20000])], ['a' => [item('a', 'Soda', 1, 5000)]], [
    'e1' => expense('e1', '2026-11-12', 3000),
    'e2' => expense('e2', '2026-11-12', 1500, ['userId' => null, 'note' => 'fuel']),
    'old' => expense('old', '2026-11-11', 999),
    'new' => expense('new', '2026-11-13', 888),
]);
same($r['expensesTotalCents'], 4500, 'only the expenses dated in the range');
same($r['expenseCount'], 2, 'expense count');
same($r['grossProfitCents'], 15000, 'gross profit');
same($r['netProfitCents'], 15000 - 4500, 'net profit = gross profit - expenses');
same($r['grandTotalCents'], 20000 - 4500, 'grand total = sales - expenses (not cost-adjusted)');
same(array_column($r['expenses'], 'enteredByName'), ['Wanjiru', 'System Admin'], 'an expense with no user is entered by System Admin');
same($r['expenses'][1]['note'], 'fuel', 'a note is carried');
same($r['expenses'][0]['note'], null, 'no note is null');
same(agg([], [], ['b' => expense('b', '2026-11-12', 1, ['note' => ''])])['expenses'][0]['note'], null, 'a blank note is null too');
$r = agg([], [], [
    'b' => expense('b', '2026-11-12', 1, ['createdAt' => '2026-11-12T09:00:00.000000Z']),
    'a' => expense('a', '2026-11-12', 1, ['createdAt' => '2026-11-12T09:00:00.000000Z']),
    'c' => expense('c', '2026-11-12', 1, ['createdAt' => '2026-11-12T07:00:00.000000Z']),
]);
same(array_column($r['expenses'], 'id'), ['c', 'a', 'b'], 'expenses: oldest first, then by id');

// ---- lists are bounded, totals are not ---------------------------------------------------------------------------
same(LiteReport::MAX_LISTED, 500, 'the lists are cut at 500 rows');
$many = [];
for ($i = 0; $i < LiteReport::MAX_LISTED + 1; $i++) {
    $many['s' . $i] = sale('s' . $i, ['createdAt' => sprintf('2026-11-12T10:%02d:%02d.000Z', intdiv($i, 60) % 60, $i % 60), 'totalCents' => 100]);
}
$r = agg($many);
same($r['transactionCount'], LiteReport::MAX_LISTED + 1, 'the count covers every sale');
same($r['salesTotalCents'], (LiteReport::MAX_LISTED + 1) * 100, 'the total covers every sale');
same(count($r['sales']), LiteReport::MAX_LISTED, 'the list stops at the limit');
same($r['salesTruncated'], true, 'and says so');
same(agg(['a' => sale('a')])['salesTruncated'], false, 'a short list is not truncated');
$manyExpenses = [];
for ($i = 0; $i < 501; $i++) {
    $manyExpenses['x' . $i] = expense('x' . $i, '2026-11-12', 10);
}
$r = agg([], [], $manyExpenses);
same([$r['expenseCount'], $r['expensesTotalCents'], count($r['expenses']), $r['expensesTruncated']], [501, 5010, 500, true], 'expenses: every one is added up, only 500 are listed, and it says so');
same(agg([], [], ['x' => expense('x', '2026-11-12', 10)])['expensesTruncated'], false, 'a short expense list is not truncated');

// ---- the dashboard's day-by-day figures --------------------------------------------------------------------------
$dayBounds = ['2026-11-11T00:00:00.000Z', '2026-11-12T00:00:00.000Z', '2026-11-13T00:00:00.000Z'];
$dayDates = ['2026-11-11', '2026-11-12', '2026-11-13'];
$twoDays = LiteReport::buckets([
    'a' => sale('a', ['createdAt' => '2026-11-11T10:00:00.000Z', 'totalCents' => 5000]),
    'b' => sale('b', ['createdAt' => '2026-11-12T00:00:00.000Z', 'totalCents' => 7000]),
    'c' => sale('c', ['createdAt' => '2026-11-12T23:59:59.999Z', 'totalCents' => 3000]),
    'd' => sale('d', ['createdAt' => '2026-11-13T00:00:00.000Z', 'totalCents' => 99999]),
], [
    'a' => [item('a', 'X', 1, 2000)],
    'b' => [item('b', 'X', 1, 1000)],
], [
    'e1' => expense('e1', '2026-11-11', 500),
    'e2' => expense('e2', '2026-11-12', 700),
    'e3' => expense('e3', '2026-11-13', 99999),
], ['u1' => 'Wanjiru'], $dayBounds, $dayDates);
same(count($twoDays), 2, 'two periods from three edges');
same([$twoDays[0]['salesTotalCents'], $twoDays[0]['transactionCount'], $twoDays[0]['grossProfitCents'], $twoDays[0]['expensesTotalCents'], $twoDays[0]['netProfitCents']], [5000, 1, 3000, 500, 2500], 'dashboard: the first day');
same([$twoDays[1]['salesTotalCents'], $twoDays[1]['transactionCount'], $twoDays[1]['grossProfitCents'], $twoDays[1]['expensesTotalCents'], $twoDays[1]['netProfitCents']], [10000, 2, 9000, 700, 8300], 'dashboard: the second day (a sale at its first instant counts, one at the next day\'s first instant does not)');
same(array_keys($twoDays[0]), ['fromUtc', 'toUtc', 'fromDate', 'toDate', 'salesTotalCents', 'transactionCount', 'grossProfitCents', 'expensesTotalCents', 'netProfitCents'], 'dashboard: only the figures, no lists');
same([$twoDays[1]['fromUtc'], $twoDays[1]['toUtc'], $twoDays[1]['fromDate'], $twoDays[1]['toDate']], ['2026-11-12T00:00:00.000Z', '2026-11-13T00:00:00.000Z', '2026-11-12', '2026-11-13'], 'dashboard: each period says which period it is');
$quiet = LiteReport::buckets([], [], [], [], $dayBounds, $dayDates);
same([$quiet[0]['salesTotalCents'], $quiet[0]['transactionCount'], $quiet[1]['expensesTotalCents'], $quiet[1]['netProfitCents']], [0, 0, 0, 0], 'dashboard: a quiet day is zeros');
same(count(LiteReport::buckets([], [], [], [], ['2026-11-11T00:00:00.000Z'], ['2026-11-11'])), 0, 'dashboard: one edge is no period');

// ---- the database part: the newest version wins ------------------------------------------------------------------
$name = 'nexapos_lite_report_test_' . getmypid();
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
try {
    $admin = new PDO("mysql:host=$dbHost;port=$dbPort;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, 'The database part needs the local MySQL: ' . $e->getMessage() . "\n");
    exit(2);
}
$admin->exec("DROP DATABASE IF EXISTS `$name`");
$admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$name;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE sync_changes (id BIGINT AUTO_INCREMENT PRIMARY KEY, shop_id INT NOT NULL, table_name VARCHAR(40) NOT NULL, row_id VARCHAR(40) NOT NULL,
        device_id VARCHAR(64) NOT NULL, local_rev INT NOT NULL, updated_at VARCHAR(40) NOT NULL, payload MEDIUMTEXT NOT NULL, INDEX (shop_id, id),
        UNIQUE KEY uq (shop_id, device_id, local_rev))');
    $rev = 0;
    $put = static function (int $shop, string $table, array $payload, string $device = 'dev-a') use ($pdo, &$rev): void {
        $stmt = $pdo->prepare('INSERT INTO sync_changes (shop_id, table_name, row_id, device_id, local_rev, updated_at, payload) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$shop, $table, $payload['id'], $device, ++$rev, $payload['updatedAt'] ?? '2026-11-12T10:00:00.000000Z', json_encode($payload, JSON_THROW_ON_ERROR)]);
    };
    $u = static fn (string $id, string $name, array $extra = []) => array_merge(['id' => $id, 'name' => $name, 'updatedAt' => '2026-01-01T00:00:00.000000Z', 'deletedAt' => null], $extra);

    // shop 1: the real data. users, two paid sales, one sale that moves pending -> paid, one that was cancelled.
    $put(1, 'users', $u('u1', 'Wanjiru'));
    $put(1, 'users', $u('u1', 'Wanjiru Old Name', ['updatedAt' => '2025-01-01T00:00:00.000000Z']));      // an OLDER version arriving later
    $put(1, 'users', $u('u2', 'Otieno'));
    $put(1, 'sales', sale('s1', ['updatedAt' => '2026-11-12T10:00:00.000000Z']));
    $put(1, 'sale_items', item('s1', 'Soda', 2, 3000));
    $put(1, 'sales', sale('s2', ['userId' => 'u2', 'paymentMethod' => 'paystack', 'totalCents' => 8000, 'status' => 'pending', 'createdAt' => '2026-11-12T11:00:00.000000Z', 'updatedAt' => '2026-11-12T11:00:00.000000Z']));
    $put(1, 'sale_items', item('s2', 'Bread', 1, 2000));
    $put(1, 'sales', sale('s2', ['userId' => 'u2', 'paymentMethod' => 'paystack', 'totalCents' => 8000, 'status' => 'paid', 'createdAt' => '2026-11-12T11:00:00.000000Z', 'updatedAt' => '2026-11-12T11:00:05.000000Z']), 'dev-b'); // settled later, by another device
    $put(1, 'sales', sale('s3', ['status' => 'pending', 'createdAt' => '2026-11-12T12:00:00.000000Z', 'updatedAt' => '2026-11-12T12:00:00.000000Z']));
    $put(1, 'sales', sale('s3', ['status' => 'cancelled', 'createdAt' => '2026-11-12T12:00:00.000000Z', 'updatedAt' => '2026-11-12T12:01:00.000000Z']));
    $put(1, 'sales', sale('s4', ['createdAt' => '2026-11-11T10:00:00.000000Z', 'updatedAt' => '2026-11-11T10:00:00.000000Z']));      // yesterday
    $put(1, 'sales', sale('s5', ['updatedAt' => '2026-11-12T10:30:00.000000Z', 'createdAt' => '2026-11-12T10:30:00.000000Z']));       // deleted below
    $put(1, 'sales', sale('s5', ['updatedAt' => '2026-11-12T10:31:00.000000Z', 'createdAt' => '2026-11-12T10:30:00.000000Z', 'deletedAt' => '2026-11-12T10:31:00.000000Z']));
    // an item that was removed from a sale, and one whose newest version is deleted
    $put(1, 'sale_items', item('s1', 'Gone', 5, 9999, 's1-gone'));
    $put(1, 'sale_items', array_merge(item('s1', 'Gone', 5, 9999, 's1-gone'), ['updatedAt' => '2026-11-12T10:05:00.000000Z', 'deletedAt' => '2026-11-12T10:05:00.000000Z']));
    $put(1, 'expenses', ['id' => 'e1', 'expenseDate' => '2026-11-12', 'title' => 'Rent', 'amountCents' => 2500, 'note' => null, 'userId' => 'u1', 'createdAt' => '2026-11-12T08:00:00.000000Z', 'updatedAt' => '2026-11-12T08:00:00.000000Z', 'deletedAt' => null]);
    $put(1, 'expenses', ['id' => 'e2', 'expenseDate' => '2026-11-12', 'title' => 'Wrong amount', 'amountCents' => 100, 'note' => null, 'userId' => 'u1', 'createdAt' => '2026-11-12T08:30:00.000000Z', 'updatedAt' => '2026-11-12T08:30:00.000000Z', 'deletedAt' => null]);
    $put(1, 'expenses', ['id' => 'e2', 'expenseDate' => '2026-11-12', 'title' => 'Fixed amount', 'amountCents' => 400, 'note' => 'fixed', 'userId' => 'u1', 'createdAt' => '2026-11-12T08:30:00.000000Z', 'updatedAt' => '2026-11-12T08:45:00.000000Z', 'deletedAt' => null]);
    $put(1, 'expenses', ['id' => 'e3', 'expenseDate' => '2026-11-11', 'title' => 'Yesterday', 'amountCents' => 7777, 'note' => null, 'userId' => 'u1', 'createdAt' => '2026-11-11T08:00:00.000000Z', 'updatedAt' => '2026-11-11T08:00:00.000000Z', 'deletedAt' => null]);

    // shop 4: the edges of the period, in the database itself.
    $put(4, 'users', $u('u1', 'Edge'));
    $put(4, 'sales', sale('edge-start', ['createdAt' => '2026-11-12T00:00:00.000Z', 'updatedAt' => '2026-11-12T00:00:00.000Z', 'totalCents' => 1000]));
    $put(4, 'sales', sale('edge-last', ['createdAt' => '2026-11-12T23:59:59.999Z', 'updatedAt' => '2026-11-12T23:59:59.999Z', 'totalCents' => 2000]));
    $put(4, 'sales', sale('edge-end', ['createdAt' => '2026-11-13T00:00:00.000Z', 'updatedAt' => '2026-11-13T00:00:00.000Z', 'totalCents' => 4000]));
    $put(4, 'sales', sale('edge-before', ['createdAt' => '2026-11-11T23:59:59.999Z', 'updatedAt' => '2026-11-11T23:59:59.999Z', 'totalCents' => 8000]));
    foreach ([['x-start', '2026-11-12', 10], ['x-end', '2026-11-13', 20], ['x-before', '2026-11-11', 40]] as [$eid, $edate, $eamount]) {
        $put(4, 'expenses', ['id' => $eid, 'expenseDate' => $edate, 'title' => $eid, 'amountCents' => $eamount, 'note' => null, 'userId' => 'u1', 'createdAt' => '2026-11-12T08:00:00.000000Z', 'updatedAt' => '2026-11-12T08:00:00.000000Z', 'deletedAt' => null]);
    }

    // shop 2: someone else's shop must never leak in.
    $put(2, 'users', $u('u1', 'Other Shop Person'));
    $put(2, 'sales', sale('other', ['totalCents' => 99999, 'updatedAt' => '2026-11-12T10:00:00.000000Z']));
    $put(2, 'expenses', ['id' => 'oe', 'expenseDate' => '2026-11-12', 'title' => 'Other', 'amountCents' => 55555, 'note' => null, 'userId' => 'u1', 'createdAt' => '2026-11-12T08:00:00.000000Z', 'updatedAt' => '2026-11-12T08:00:00.000000Z', 'deletedAt' => null]);

    $report = LiteReport::build($pdo, 1, FROM, TO, '2026-11-12', '2026-11-13');
    same($report['transactionCount'], 2, 'db: s1 and s2 (settled by another device) are the paid sales; pending/cancelled/deleted/yesterday are not');
    same(array_column($report['sales'], 'id'), ['s2', 's1'], 'db: newest first');
    same($report['salesTotalCents'], 10000 + 8000, 'db: total');
    same($report['grossProfitCents'], (10000 - 6000) + (8000 - 2000), 'db: profit uses only the items that are still there (the deleted one is ignored)');
    same($report['cashReceivedCents'], 10000, 'db: cash');
    same($report['mpesaCents'], 8000, 'db: M-Pesa');
    same($report['sales'][1]['itemNames'], 'Soda x2', 'db: the deleted item is not named');
    same($report['sales'][0]['cashierName'], 'Otieno', 'db: cashier of s2');
    same($report['sales'][1]['cashierName'], 'Wanjiru', 'db: the NEWEST version of a user wins, not the one that arrived last');
    same($report['expensesTotalCents'], 2500 + 400, 'db: the edited expense counts at its newest amount; yesterday is not in range');
    same(array_column($report['expenses'], 'title'), ['Rent', 'Fixed amount'], 'db: expenses, oldest first, newest version');
    same($report['netProfitCents'], 10000 - 2900, 'db: net profit');
    same($report['grandTotalCents'], 18000 - 2900, 'db: grand total');

    $other = LiteReport::build($pdo, 2, FROM, TO, '2026-11-12', '2026-11-13');
    same([$other['salesTotalCents'], $other['expensesTotalCents'], $other['transactionCount']], [99999, 55555, 1], 'db: the other shop sees only its own');
    same($report['salesTotalCents'] + $other['salesTotalCents'], 18000 + 99999, 'db: nothing crossed over');

    $yesterday = LiteReport::build($pdo, 1, '2026-11-11T00:00:00.000Z', '2026-11-12T00:00:00.000Z', '2026-11-11', '2026-11-12');
    same([$yesterday['transactionCount'], $yesterday['salesTotalCents'], $yesterday['expensesTotalCents']], [1, 10000, 7777], 'db: yesterday has its own sale and expense');

    $empty = LiteReport::build($pdo, 1, '2030-01-01T00:00:00.000Z', '2030-01-02T00:00:00.000Z', '2030-01-01', '2030-01-02');
    same([$empty['transactionCount'], $empty['salesTotalCents'], $empty['sales'], $empty['expenses']], [0, 0, [], []], 'db: a day with nothing is empty, not an error');

    $edges = LiteReport::build($pdo, 4, FROM, TO, '2026-11-12', '2026-11-13');
    same([$edges['transactionCount'], $edges['salesTotalCents']], [2, 3000], 'db: a sale at the very start and one a millisecond before the end count; one at the end and one just before the start do not');
    same([$edges['expenseCount'], $edges['expensesTotalCents']], [1, 10], 'db: an expense dated the first day counts; the day after the period and the day before do not');

    $edgeDays = LiteReport::days($pdo, 4, $dayBounds, $dayDates);
    same([$edgeDays[0]['salesTotalCents'], $edgeDays[0]['expensesTotalCents']], [8000, 40], 'db dashboard: the day before has its own sale and expense');
    same([$edgeDays[1]['salesTotalCents'], $edgeDays[1]['transactionCount'], $edgeDays[1]['expensesTotalCents']], [3000, 2, 10], 'db dashboard: the next day has the two sales inside it, and not the one at its very end');
    $oneDay = LiteReport::days($pdo, 1, [FROM, TO], ['2026-11-12', '2026-11-13']);
    same([$oneDay[0]['salesTotalCents'], $oneDay[0]['transactionCount'], $oneDay[0]['grossProfitCents'], $oneDay[0]['expensesTotalCents'], $oneDay[0]['netProfitCents']],
        [$report['salesTotalCents'], $report['transactionCount'], $report['grossProfitCents'], $report['expensesTotalCents'], $report['netProfitCents']], 'db dashboard: one period gives the same figures as the report');
    same(LiteReport::days($pdo, 2, [FROM, TO], ['2026-11-12', '2026-11-13'])[0]['salesTotalCents'], 99999, 'db dashboard: another shop sees only its own');

    $none = LiteReport::build($pdo, 3, FROM, TO, '2026-11-12', '2026-11-13');
    same($none['transactionCount'], 0, 'db: a shop with no records');
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `$name`");
}

if ($failures > 0) {
    fwrite(STDERR, "$failures of $checks checks FAILED\n");
    exit(1);
}
echo "Lite report tests passed: $checks checks.\n";
