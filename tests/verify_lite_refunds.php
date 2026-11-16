<?php

declare(strict_types=1);

// Refunds in the shop's Reports and dashboard for the lite till (app/Services/LiteReport.php), against the records the
// REAL app writes for them (tests/fixtures/sync_guard/refund_scenarios.json, made by the app's own tests): a refund is
// its own sale with negative amounts, so Paid Sales, Refunds and Grand Total must come out as the apps show them.
// The numbers below are worked out by hand from what was sold and refunded, not read back from the code.
// The database part needs MySQL (DB_HOST / DB_PORT / DB_USER / DB_PASS, like the rest of the platform).
//
//   php tests/verify_lite_refunds.php

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

const WIDE_FROM = '2026-01-01T00:00:00.000Z';
const WIDE_TO = '2027-01-01T00:00:00.000Z';

$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/sync_guard/refund_scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
$scenarios = [];
foreach ($fixture['scenarios'] as $scenario) {
    $scenarios[$scenario['name']] = $scenario;
}

/**
 * The shop's records after a scenario, resolved to the newest version of each, as LiteReport receives them:
 * [paid sales (refunds included), items by sale, user names, how much of each sale is refunded, sale numbers].
 */
function resolve(array $scenario): array
{
    $sales = [];
    $itemsBySale = [];
    foreach ($scenario['stages'] as $stage) {
        foreach ($stage['changes'] as $change) {
            $payload = $change['payload'];
            if ($change['table_name'] === 'sales') {
                $sales[$payload['id']] = $payload;
            } elseif ($change['table_name'] === 'sale_items') {
                $itemsBySale[$payload['saleId']][] = $payload;
            }
        }
    }
    $names = [];
    foreach ($scenario['context']['users'] as $user) {
        $names[$user['id']] = $user['name'];
    }
    $refunded = [];
    $numbers = [];
    foreach ($sales as $id => $sale) {
        $numbers[$id] = $sale['saleNumber'];
        if (($sale['saleType'] ?? '') === 'refund') {
            $refunded[$sale['refundOfSaleId']] = ($refunded[$sale['refundOfSaleId']] ?? 0) - $sale['totalCents'];
        }
    }
    return [$sales, $itemsBySale, $names, $refunded, $numbers];
}

function reportOf(array $scenario): array
{
    [$sales, $items, $names, $refunded, $numbers] = resolve($scenario);
    return LiteReport::aggregate($sales, $items, [], $names, WIDE_FROM, WIDE_TO, '2026-01-01', '2027-01-01', false, $refunded, $numbers);
}

/** @param list<array<string,mixed>> $rows */
function sorted(array $rows, string $key): array
{
    $values = array_column($rows, $key);
    sort($values);
    return $values;
}

// ---- a cash sale refunded whole and put back in stock ---------------------------------------------------------------
$r = reportOf($scenarios['refund_whole_cash_sale']);
same($r['salesTotalCents'], 45050, 'whole cash refund: Paid Sales is what the customer paid');
same($r['refundsTotalCents'], 45050, 'whole cash refund: Refunds is all of it');
same($r['grandTotalCents'], 0, 'whole cash refund: the shop kept nothing');
same($r['transactionCount'], 1, 'whole cash refund: a refund is not a transaction');
same($r['refundCount'], 1, 'whole cash refund: one refund');
same($r['cashReceivedCents'], 45050, 'whole cash refund: cash received stays what customers paid');
same($r['mpesaCents'], 0, 'whole cash refund: no M-Pesa');
same($r['grossProfitCents'], 0, 'whole cash refund: put back on the shelf, so the margin goes with it (18050 - 18050)');
same($r['netProfitCents'], 0, 'whole cash refund: net profit');
same(count($r['sales']), 1, 'whole cash refund: the refund is not among the sales');
same($r['sales'][0]['refundedCents'], 45050, 'whole cash refund: the sale says it was refunded in full');
same($r['sales'][0]['status'], 'paid', 'whole cash refund: and is still a paid sale');
$refund = $r['refunds'][0];
same($refund['totalCents'], 45050, 'whole cash refund: a refund is listed as a positive amount of money that went back');
same($refund['reason'], 'Wrong goods', 'whole cash refund: the reason');
same($refund['processedByName'], 'Manager', 'whole cash refund: who gave the money back');
same($refund['paymentMethod'], 'cash', 'whole cash refund: how');
same($refund['originalSaleNumber'], $r['sales'][0]['saleNumber'], 'whole cash refund: which sale it refunds');
same(sorted([['n' => $refund['itemNames']]], 'n'), ['Widget x2, Gadget x1'], 'whole cash refund: what went back, as positive quantities, in the order of the sale');
same($r['refundsTruncated'], false, 'whole cash refund: not truncated');

// ---- an M-Pesa sale refunded in two parts, the second damaged (not put back) --------------------------------------------
$r = reportOf($scenarios['refund_partial_mpesa_sale_in_two_parts']);
same([$r['salesTotalCents'], $r['mpesaCents'], $r['cashReceivedCents']], [30000, 30000, 0], 'm-pesa parts: paid by M-Pesa');
same([$r['refundsTotalCents'], $r['refundCount']], [30000, 2], 'm-pesa parts: two refunds adding up to the whole');
same($r['grandTotalCents'], 0, 'm-pesa parts: grand total');
same($r['grossProfitCents'], -12000, 'm-pesa parts: sale 12000, then -4000 (one back on the shelf) and -20000 (two thrown away)');
same(sorted($r['refunds'], 'reason'), ['Bent', 'Damaged'], 'm-pesa parts: both reasons');
same(sorted($r['refunds'], 'totalCents'), [10000, 20000], 'm-pesa parts: both amounts');
same($r['sales'][0]['refundedCents'], 30000, 'm-pesa parts: refunded in full');
same($r['refunds'][0]['paymentMethod'], 'mpesa_manual', 'm-pesa parts: paid back the way it was paid');

// ---- a discounted sale refunded in three parts --------------------------------------------------------------------------
$r = reportOf($scenarios['refund_discounted_sale_in_three_parts']);
same([$r['salesTotalCents'], $r['refundsTotalCents']], [20000, 20000], 'discounted: the refunds add up to exactly what was paid, to the cent');
same(sorted($r['refunds'], 'totalCents'), [6666, 6667, 6667], 'discounted: the rounding is carried, never lost');
same($r['grandTotalCents'], 0, 'discounted: grand total');
same($r['grossProfitCents'], 0, 'discounted: all three put back, so the margin of 2000 is gone: 2000 - 667 - 666 - 667');
same($r['refundCount'], 3, 'discounted: three refunds');

// ---- a manual item ------------------------------------------------------------------------------------------------------------
$r = reportOf($scenarios['refund_manual_item']);
same([$r['salesTotalCents'], $r['refundsTotalCents'], $r['grandTotalCents']], [15000, 5000, 10000], 'manual item: only the delivery went back');
same($r['grossProfitCents'], 4000, 'manual item: widget margin 4000 stays; the delivery (cost 0) was 5000 of profit, all refunded: 9000 - 5000');
same($r['sales'][0]['refundedCents'], 5000, 'manual item: part refunded');
same($r['refunds'][0]['itemNames'], 'Delivery x1', 'manual item: the item that went back');

// ---- the same sale without its refunds is as before ------------------------------------------------------------------------------
$fresh = $scenarios['refund_whole_cash_sale'];
$fresh['stages'] = [$fresh['stages'][0]];
$r = reportOf($fresh);
same([$r['salesTotalCents'], $r['refundsTotalCents'], $r['grandTotalCents'], $r['refundCount'], $r['refunds']], [45050, 0, 45050, 0, []], 'before the refund: nothing refunded');
same($r['sales'][0]['refundedCents'], 0, 'before the refund: nothing refunded on the sale');
same($r['grossProfitCents'], 18050, 'before the refund: gross profit as always');

// ---- a refund on another day than the sale -----------------------------------------------------------------------------------
[$sales, $items, $names, $refunded, $numbers] = resolve($scenarios['refund_whole_cash_sale']);
$saleId = null;
$refundId = null;
foreach ($sales as $id => $sale) {
    if ($sale['saleType'] === 'refund') {
        $refundId = $id;
    } else {
        $saleId = $id;
    }
}
$sales[$saleId]['createdAt'] = '2026-11-15T09:00:00.000Z';
foreach ($items[$saleId] as $k => $it) {
    $items[$saleId][$k]['createdAt'] = '2026-11-15T09:00:00.000Z';
}
$sales[$refundId]['createdAt'] = '2026-11-16T09:00:00.000Z';
$dayOne = LiteReport::aggregate($sales, $items, [], $names, '2026-11-15T00:00:00.000Z', '2026-11-16T00:00:00.000Z', '2026-11-15', '2026-11-16', false, $refunded, $numbers);
$dayTwo = LiteReport::aggregate($sales, $items, [], $names, '2026-11-16T00:00:00.000Z', '2026-11-17T00:00:00.000Z', '2026-11-16', '2026-11-17', false, $refunded, $numbers);
same([$dayOne['salesTotalCents'], $dayOne['refundsTotalCents'], $dayOne['grandTotalCents'], $dayOne['sales'][0]['refundedCents']], [45050, 0, 45050, 45050], 'other day: the sale\'s day is not rewritten, but the sale says it has since gone back');
same([$dayTwo['salesTotalCents'], $dayTwo['transactionCount'], $dayTwo['refundsTotalCents'], $dayTwo['grandTotalCents']], [0, 0, 45050, -45050], 'other day: the refund is counted on the day the money went back');
same($dayTwo['refunds'][0]['originalSaleNumber'], $numbers[$saleId], 'other day: the refund still names the sale from the earlier day');
$dayTwoNoOriginal = LiteReport::aggregate($sales, $items, [], $names, '2026-11-16T00:00:00.000Z', '2026-11-17T00:00:00.000Z', '2026-11-16', '2026-11-17');
same($dayTwoNoOriginal['refunds'][0]['originalSaleNumber'], null, 'other day: with the original unknown the refund still shows, without a number');

// ---- the dashboard: Sales Today is net of refunds ------------------------------------------------------------------------------------
$buckets = LiteReport::buckets($sales, $items, [], $names,
    ['2026-11-15T00:00:00.000Z', '2026-11-16T00:00:00.000Z', '2026-11-17T00:00:00.000Z'], ['2026-11-15', '2026-11-16', '2026-11-17']);
same([$buckets[0]['salesTotalCents'], $buckets[0]['refundsCents'], $buckets[0]['transactionCount'], $buckets[0]['grossProfitCents']], [45050, 0, 1, 18050], 'dashboard day 1: the sale');
same([$buckets[1]['salesTotalCents'], $buckets[1]['refundsCents'], $buckets[1]['transactionCount'], $buckets[1]['grossProfitCents'], $buckets[1]['netProfitCents']], [-45050, 45050, 0, -18050, -18050], 'dashboard day 2: net of the refund, no transaction, the margin taken back');
$oneDay = LiteReport::buckets($sales, $items, [], $names, ['2026-11-15T00:00:00.000Z', '2026-11-17T00:00:00.000Z'], ['2026-11-15', '2026-11-17']);
same([$oneDay[0]['salesTotalCents'], $oneDay[0]['refundsCents'], $oneDay[0]['transactionCount'], $oneDay[0]['grossProfitCents']], [0, 45050, 1, 0], 'dashboard: both days together net to nothing');

// ---- refunds are listed newest first, a tie broken by the number ----------------------------------------------------------
$refundOf = static fn (string $id, string $number, string $at): array => ['id' => $id, 'saleNumber' => $number, 'userId' => 'u1', 'saleType' => 'refund', 'paymentMethod' => 'cash', 'status' => 'paid',
    'subtotalCents' => -100, 'discountCents' => 0, 'totalCents' => -100, 'createdAt' => $at, 'updatedAt' => $at, 'deletedAt' => null, 'refundOfSaleId' => 'x', 'refundReason' => 'r', 'cashReceivedCents' => null];
$r = LiteReport::aggregate([
    'a' => $refundOf('a', 'R-A', '2026-11-16T09:00:00.000Z'),
    'b' => $refundOf('b', 'R-B', '2026-11-16T10:00:00.000Z'),
    'c' => $refundOf('c', 'R-C', '2026-11-16T10:00:00.000Z'),
], [], [], ['u1' => 'Wanjiru'], WIDE_FROM, WIDE_TO, '2026-01-01', '2027-01-01');
same(array_column($r['refunds'], 'id'), ['c', 'b', 'a'], 'refunds are listed newest first, and the later number first when they came at the same moment');

// ---- a refund outside the period is not in it, and one that is not paid is not a refund ------------------------------------------
$r = LiteReport::aggregate(['a' => $refundOf('a', 'R-A', '2026-11-15T23:59:59.999Z'), 'b' => array_merge($refundOf('b', 'R-B', '2026-11-16T09:00:00.000Z'), ['status' => 'cancelled'])], [], [], [], '2026-11-16T00:00:00.000Z', '2026-11-17T00:00:00.000Z', '2026-11-16', '2026-11-17');
same([$r['refundCount'], $r['refundsTotalCents'], $r['refunds']], [0, 0, []], 'a refund before the period and a cancelled one are not counted');
$r = LiteReport::aggregate(['a' => $refundOf('a', 'R-A', '2026-11-16T00:00:00.000Z'), 'b' => $refundOf('b', 'R-B', '2026-11-17T00:00:00.000Z')], [], [], [], '2026-11-16T00:00:00.000Z', '2026-11-17T00:00:00.000Z', '2026-11-16', '2026-11-17');
same([$r['refundCount'], $r['refundsTotalCents']], [1, 100], 'a refund at the very start of the period counts, one at the very end does not');
same($r['refunds'][0]['processedByName'], 'Unknown', 'a refund by someone not in the users list says Unknown, like a sale');

// ---- more than the list can hold: counted, not all listed -------------------------------------------------------------------------------
$many = [];
$manyItems = [];
for ($i = 0; $i < 501; $i++) {
    $many['r' . $i] = ['id' => 'r' . $i, 'saleNumber' => 'R' . $i, 'userId' => 'u1', 'saleType' => 'refund', 'paymentMethod' => 'cash', 'status' => 'paid', 'subtotalCents' => -10, 'discountCents' => 0,
        'totalCents' => -10, 'createdAt' => '2026-11-16T09:00:00.000Z', 'updatedAt' => '2026-11-16T09:00:00.000Z', 'deletedAt' => null, 'refundOfSaleId' => 'x', 'refundReason' => 'r', 'cashReceivedCents' => null];
}
$r = LiteReport::aggregate($many, $manyItems, [], ['u1' => 'Wanjiru'], WIDE_FROM, WIDE_TO, '2026-01-01', '2027-01-01');
same([$r['refundCount'], $r['refundsTotalCents'], count($r['refunds']), $r['refundsTruncated']], [501, 5010, 500, true], 'many refunds: every one is added up, only 500 are listed, and it says so');
same([$r['transactionCount'], $r['salesTotalCents']], [0, 0], 'many refunds: and none of them is a sale');

// ---- the database part -------------------------------------------------------------------------------------------------------------------
$name = 'nexapos_lite_refund_test_' . getmypid();
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
        $stmt->execute([$shop, $table, $payload['id'], $device, ++$rev, $payload['updatedAt'], json_encode($payload, JSON_THROW_ON_ERROR)]);
    };
    $saleRow = static fn (string $id, string $day, array $extra = []): array => array_merge([
        'id' => $id, 'createdAt' => "$day.000Z", 'updatedAt' => "$day.000Z", 'deletedAt' => null, 'saleNumber' => 'N-' . $id, 'userId' => 'u1',
        'saleType' => 'retail', 'paymentMethod' => 'cash', 'subtotalCents' => 10000, 'discountCents' => 0, 'totalCents' => 10000,
        'status' => 'paid', 'cashReceivedCents' => null, 'refundOfSaleId' => null, 'refundReason' => null,
    ], $extra);
    $itemRow = static fn (string $id, string $saleId, string $day, int $qty): array => [
        'id' => $id, 'saleId' => $saleId, 'itemName' => 'Soda', 'quantity' => $qty, 'costPriceCents' => 6000, 'createdAt' => "$day.000Z",
        'updatedAt' => "$day.000Z", 'deletedAt' => null, 'refundOfItemId' => null,
    ];
    $put(1, 'users', ['id' => 'u1', 'name' => 'Wanjiru', 'updatedAt' => '2026-01-01T00:00:00.000Z', 'deletedAt' => null]);
    $put(1, 'users', ['id' => 'u2', 'name' => 'Otieno', 'updatedAt' => '2026-01-01T00:00:00.000Z', 'deletedAt' => null]);

    // Monday: a sale of 2 sodas (10000, cost 6000 each - a loss-making toy, but easy sums).
    $put(1, 'sales', $saleRow('s1', '2026-11-16T08:00:00'));
    $put(1, 'sale_items', $itemRow('i1', 's1', '2026-11-16T08:00:00', 2));
    // Tuesday: one soda refunded (back on the shelf), by Otieno.
    $put(1, 'sales', $saleRow('r1', '2026-11-17T09:00:00', ['saleType' => 'refund', 'saleNumber' => 'N-r1', 'userId' => 'u2', 'subtotalCents' => -5000, 'totalCents' => -5000, 'refundOfSaleId' => 's1', 'refundReason' => 'Bent']));
    $put(1, 'sale_items', array_merge($itemRow('ri1', 'r1', '2026-11-17T09:00:00', -1), ['refundOfItemId' => 'i1']));
    // A refund that was later removed (deleted), and one that is not paid: neither counts.
    $put(1, 'sales', $saleRow('r2', '2026-11-17T10:00:00', ['saleType' => 'refund', 'subtotalCents' => -1000, 'totalCents' => -1000, 'refundOfSaleId' => 's1', 'deletedAt' => '2026-11-17T10:01:00.000Z', 'updatedAt' => '2026-11-17T10:01:00.000Z']));
    $put(1, 'sales', $saleRow('r3', '2026-11-17T11:00:00', ['saleType' => 'refund', 'status' => 'pending', 'subtotalCents' => -2000, 'totalCents' => -2000, 'refundOfSaleId' => 's1']));
    // Another shop's refund of a sale with the very same id must never leak in.
    $put(2, 'sales', $saleRow('s1', '2026-11-16T08:00:00', ['totalCents' => 99999]));
    $put(2, 'sales', $saleRow('rx', '2026-11-17T09:00:00', ['saleType' => 'refund', 'subtotalCents' => -77777, 'totalCents' => -77777, 'refundOfSaleId' => 's1']));

    // Shop 3: another device's clock is a little behind, so its refund is stamped BEFORE the sale it gives back.
    $put(3, 'users', ['id' => 'u1', 'name' => 'Skew', 'updatedAt' => '2026-01-01T00:00:00.000Z', 'deletedAt' => null]);
    $put(3, 'sales', $saleRow('sk-sale', '2026-11-16T00:00:30'));
    $put(3, 'sales', $saleRow('sk-refund', '2026-11-15T23:59:59', ['saleType' => 'refund', 'subtotalCents' => -4000, 'totalCents' => -4000, 'refundOfSaleId' => 'sk-sale', 'refundReason' => 'skew']), 'dev-b');
    $skew = LiteReport::build($pdo, 3, '2026-11-16T00:00:00.000Z', '2026-11-17T00:00:00.000Z', '2026-11-16', '2026-11-17');
    same([$skew['sales'][0]['refundedCents'], $skew['refundsTotalCents']], [4000, 0], 'db skew: the sale says it was refunded even when the other device clock stamped the refund earlier; the refund itself belongs to the day it says');

    $monday = LiteReport::build($pdo, 1, '2026-11-16T00:00:00.000Z', '2026-11-17T00:00:00.000Z', '2026-11-16', '2026-11-17');
    same([$monday['salesTotalCents'], $monday['refundsTotalCents'], $monday['transactionCount']], [10000, 0, 1], 'db monday: the sale, no refund that day');
    same($monday['sales'][0]['refundedCents'], 5000, 'db monday: the sale shows the refund made on Tuesday, counting only the valid one');
    same($monday['grossProfitCents'], 10000 - 12000, 'db monday: gross profit of the sale alone');

    $tuesday = LiteReport::build($pdo, 1, '2026-11-17T00:00:00.000Z', '2026-11-18T00:00:00.000Z', '2026-11-17', '2026-11-18');
    same([$tuesday['salesTotalCents'], $tuesday['transactionCount'], $tuesday['refundsTotalCents'], $tuesday['refundCount'], $tuesday['grandTotalCents']], [0, 0, 5000, 1, -5000], 'db tuesday: only the refund counts (the deleted and the unpaid ones do not)');
    same($tuesday['grossProfitCents'], -5000 - (-1 * 6000), 'db tuesday: the refund takes back its margin: -5000 + 6000');
    $row = $tuesday['refunds'][0];
    same([$row['originalSaleNumber'], $row['reason'], $row['processedByName'], $row['itemNames'], $row['totalCents']], ['N-s1', 'Bent', 'Otieno', 'Soda x1', 5000], 'db tuesday: the refund names the sale from Monday, why, who and what');

    $days = LiteReport::days($pdo, 1, ['2026-11-16T00:00:00.000Z', '2026-11-17T00:00:00.000Z', '2026-11-18T00:00:00.000Z'], ['2026-11-16', '2026-11-17', '2026-11-18']);
    same([$days[0]['salesTotalCents'], $days[1]['salesTotalCents'], $days[1]['refundsCents'], $days[1]['transactionCount']], [10000, -5000, 5000, 0], 'db dashboard: Monday\'s sale, Tuesday net of the refund');

    $other = LiteReport::build($pdo, 2, '2026-11-16T00:00:00.000Z', '2026-11-18T00:00:00.000Z', '2026-11-16', '2026-11-18');
    same([$other['salesTotalCents'], $other['refundsTotalCents']], [99999, 77777], 'db: the other shop sees only its own refunds');
    same($other['sales'][0]['refundedCents'], 77777, 'db: and its own sale carries its own refund, not the first shop\'s');
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `$name`");
}

if ($failures > 0) {
    fwrite(STDERR, "$failures of $checks checks FAILED\n");
    exit(1);
}
echo "Lite refund tests passed: $checks checks.\n";
