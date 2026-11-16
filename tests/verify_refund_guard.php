<?php

declare(strict_types=1);

// A lite till may record a REFUND, but only exactly as the real app's RefundService would. This proves the sync guard
//  (1) accepts every refund record set the REAL app produces - tests/fixtures/sync_guard/refund_scenarios.json is written by the
//      app's own tests: whole and partial refunds, a discount carried across three refunds, a manual item, restocked or not,
//  (2) refuses each kind of broken or hostile refund record set below, for the right reason.
// No database or web server needed.

require __DIR__ . '/../app/Services/SyncGuard.php';

use Platform\Services\SyncGuard;
use Platform\Services\SyncGuardViolation;

$data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/sync_guard/refund_scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
$scenarios = [];
foreach ($data['scenarios'] as $scenario) {
    $scenarios[$scenario['name']] = $scenario;
}

/** A fake shop: stored records keyed "table/id", as the platform's sync_changes would hold them. */
function newShop(array $context): array
{
    $store = [];
    foreach (['products', 'users'] as $table) {
        foreach ($context[$table] as $payload) {
            $store[$table . '/' . $payload['id']] = $payload;
        }
    }
    return $store;
}

function runGuard(array $changes, string $deviceId, array $store): void
{
    $stored = static fn (string $table, string $id): ?array => $store[$table . '/' . $id] ?? null;
    $storedItems = static function (string $saleId) use ($store): array {
        return array_values(array_filter($store, static fn (array $row, string $key): bool => str_starts_with($key, 'sale_items/') && ($row['saleId'] ?? null) === $saleId, ARRAY_FILTER_USE_BOTH));
    };
    $refundsOf = static function (string $saleId) use ($store): array {
        return array_values(array_filter($store, static fn (array $row, string $key): bool => str_starts_with($key, 'sales/') && ($row['saleType'] ?? '') === 'refund' && ($row['refundOfSaleId'] ?? null) === $saleId, ARRAY_FILTER_USE_BOTH));
    };
    SyncGuard::check($changes, $deviceId, $stored, $storedItems, $refundsOf);
}

function remember(array &$store, array $changes): void
{
    foreach ($changes as $change) {
        $store[$change['table_name'] . '/' . $change['row_id']] = $change['payload'];
    }
}

$failures = [];
function expectAccepted(string $label, callable $run, array &$failures): void
{
    try {
        $run();
    } catch (SyncGuardViolation $e) {
        $failures[] = "REFUSED a good record set ($label): " . $e->getMessage();
    }
}
function expectRefused(string $label, string $fragment, callable $run, array &$failures): void
{
    try {
        $run();
        $failures[] = "ACCEPTED a bad record set ($label)";
    } catch (SyncGuardViolation $e) {
        if ($fragment !== '' && stripos($e->getMessage(), $fragment) === false) {
            $failures[] = "Refused ($label) for the wrong reason: '{$e->getMessage()}' (expected to mention '$fragment')";
        }
    }
}

// ---- 1. everything the real app produces is accepted ------------------------------------------------
$accepted = 0;
// An online sale is recorded pending and then paid; the fixture only holds its final (paid) version, so the sale itself is not a
// push that can be replayed - what is replayed is every REFUND of it. (A till's own online sales are covered by verify_sync_guard.php.)
$notReplayed = ['refund_split_cash_and_mpesa_sale_with_discount' => [1]];
foreach ($scenarios as $name => $scenario) {
    $store = newShop($scenario['context']);
    foreach ($scenario['stages'] as $stage) {
        if (in_array($stage['stage'], $notReplayed[$name] ?? [], true)) {
            remember($store, $stage['changes']);
            continue;
        }
        expectAccepted("$name stage {$stage['stage']}", static function () use ($stage, $scenario, $store): void {
            runGuard($stage['changes'], $scenario['context']['deviceId'], $store);
        }, $failures);
        remember($store, $stage['changes']);
        $accepted++;
    }
}

// ---- 2. each kind of bad record set is refused --------------------------------------------------------
/** Position of the n-th change of a table within a stage's change list. */
function at(array $changes, string $table, int $n = 0): int
{
    $seen = -1;
    foreach ($changes as $index => $change) {
        if ($change['table_name'] === $table && ++$seen === $n) {
            return $index;
        }
    }
    throw new RuntimeException("No change #$n of $table in this stage.");
}
function newId(): string
{
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-8' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
}
/** Gives a copy of a change a new row id (and keeps its payload id in step). */
function renamed(array $change): array
{
    $id = newId();
    $change['row_id'] = $id;
    $change['payload']['id'] = $id;
    return $change;
}
/** Changes the refund's sale record and keeps its envelope in step. */
function sale(array &$c, callable $edit): void
{
    $i = at($c, 'sales');
    $edit($c[$i]['payload']);
}
function line(array &$c, callable $edit, int $n = 0): void
{
    $i = at($c, 'sale_items', $n);
    $edit($c[$i]['payload']);
}

/** The sale (not a refund) in the fake shop. */
function originalSale(array $store): array
{
    foreach ($store as $key => $row) {
        if (str_starts_with($key, 'sales/') && ($row['saleType'] ?? '') !== 'refund') {
            return $row;
        }
    }
    throw new RuntimeException('No sale in the shop.');
}
/** An earlier refund of the sale that another device recorded in its own way: no lines, just amounts (and maybe a different state). */
function craftEarlier(array &$store, array $sale, int $subtotal, int $discount, ?int $cash, string $status = 'paid', ?string $deletedAt = null): void
{
    $id = newId();
    $store['sales/' . $id] = ['id' => $id, 'createdAt' => '2026-11-16T08:00:00.000Z', 'updatedAt' => '2026-11-16T08:00:00.000Z', 'deletedAt' => $deletedAt, 'localRev' => 1, 'createdByDeviceId' => 'another-device',
        'syncState' => 'local_only', 'saleNumber' => 'EARLIER-' . substr($id, 0, 4), 'userId' => $sale['userId'], 'customerName' => $sale['customerName'], 'customerPhone' => $sale['customerPhone'],
        'saleType' => 'refund', 'paymentMethod' => $sale['paymentMethod'], 'subtotalCents' => -$subtotal, 'discountCents' => -$discount, 'totalCents' => -($subtotal - $discount),
        'status' => $status, 'cashReceivedCents' => $cash === null ? null : -$cash, 'refundOfSaleId' => $sale['id'], 'refundReason' => 'earlier'];
}
/** The id of the administrator among the shop's users. */
function adminId(array $store): string
{
    foreach ($store as $key => $row) {
        if (str_starts_with($key, 'users/') && ($row['roleId'] ?? '') === '00000000-0000-4000-8000-000000000001') {
            return $row['id'];
        }
    }
    throw new RuntimeException('No administrator.');
}

const WHOLE = 'refund_whole_cash_sale';
const PARTS = 'refund_partial_mpesa_sale_in_two_parts';
const DISCOUNTED = 'refund_discounted_sale_in_three_parts';
const MANUAL = 'refund_manual_item';
const SPLIT = 'refund_split_cash_and_mpesa_sale_with_discount';
const CASHIER_ID_KEY = 'cashierId';
const MANAGER_ID_KEY = 'managerId';

$mutations = [
    // [scenario, stage, what is wrong, expected reason, mutation]
    // -- the refund record on its own
    [WHOLE, 2, 'an extra field on the refund', 'wrong fields', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['isAdmin'] = true; }); }],
    [WHOLE, 2, 'a refund that points at no sale', 'refundOfSaleId', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundOfSaleId'] = null; }); }],
    [WHOLE, 2, 'a refund with no reason', 'refundReason', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundReason'] = ''; }); }],
    [WHOLE, 2, 'a refund whose reason is only spaces', 'refundReason', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundReason'] = '   '; }); }],
    [WHOLE, 2, 'a reason with spaces round it', 'spaces', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundReason'] = ' Wrong goods '; }); }],
    [WHOLE, 2, 'a reason of 201 characters', 'refundReason', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundReason'] = str_repeat('x', 201); }); }],
    [WHOLE, 2, 'a reason with a control character', 'refundReason', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundReason'] = "bad\x01reason"; }); }],
    [WHOLE, 2, 'a refund that is not recorded as paid', 'recorded as paid', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['status'] = 'pending'; }); }],
    [WHOLE, 2, 'a positive subtotal', 'subtotalCents', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['subtotalCents'] = 45050; }); }],
    [WHOLE, 2, 'a refund of nothing', 'totalCents', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['totalCents'] = 0; $p['subtotalCents'] = 0; }); }],
    [WHOLE, 2, 'a total that is not the subtotal less the discount', 'do not add up', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['totalCents'] -= 1; }); }],
    [WHOLE, 2, 'a positive discount', 'discountCents', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['discountCents'] = 100; $p['totalCents'] = $p['subtotalCents'] - 100; }); }],
    [WHOLE, 2, 'a discount bigger than the goods', 'totalCents', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['discountCents'] = $p['subtotalCents'] - 1; $p['totalCents'] = 1; }); }],
    [WHOLE, 2, 'a refund updated at another moment than created', 'created and updated', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['updatedAt'] = '2026-11-16T09:00:01.000Z'; }); }],
    [WHOLE, 2, 'an unknown payment method', 'paymentMethod', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['paymentMethod'] = 'bitcoin'; }); }],
    [WHOLE, 2, 'a refund made on another device', 'not created by this device', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['createdByDeviceId'] = 'some-other-device'; }); }],
    [WHOLE, 1, 'an ordinary sale that points at another sale', 'only a refund', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundOfSaleId'] = newId(); }); }],
    [WHOLE, 1, 'an ordinary sale with a refund reason', 'only a refund', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundReason'] = 'sneaky'; }); }],
    [WHOLE, 2, 'a deleted refund', 'cannot delete', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['deletedAt'] = $p['createdAt']; }); }],
    // -- the sale it refunds
    [WHOLE, 2, 'a refund of a sale this shop does not have', 'not in this shop', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['refundOfSaleId'] = newId(); }); }],
    [WHOLE, 2, 'a refund of a sale that was deleted', 'not in this shop', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'sales/') && ($row['saleType'] ?? '') !== 'refund') { $s[$key]['deletedAt'] = '2026-11-16T09:01:00.000Z'; } } }],
    [WHOLE, 2, 'a refund of a sale that is not paid', 'only a paid sale', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'sales/') && ($row['saleType'] ?? '') !== 'refund') { $s[$key]['status'] = 'pending'; } } }],
    [DISCOUNTED, 3, 'a refund of a refund', 'cannot be refunded', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'sales/') && ($row['saleType'] ?? '') === 'refund') { sale($c, function (array &$p) use ($row): void { $p['refundOfSaleId'] = $row['id']; }); return; } } }],
    [WHOLE, 2, 'a refund that is already recorded', 'already recorded', function (array &$c, array &$s): void { $s['sales/' . $c[at($c, 'sales')]['row_id']] = $c[at($c, 'sales')]['payload']; }],
    [WHOLE, 2, 'another payment method than the sale\'s', 'payment method and the customer', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['paymentMethod'] = 'mpesa_manual'; }); }],
    [WHOLE, 2, 'another customer than the sale\'s', 'payment method and the customer', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['customerName'] = 'Someone else'; }); }],
    [WHOLE, 2, 'another customer phone than the sale\'s', 'payment method and the customer', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['customerPhone'] = '0711000111'; }); }],
    // -- who
    [WHOLE, 2, 'a person who is not a user of this shop', 'not an active user', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['userId'] = newId(); }); }],
    [WHOLE, 2, 'a cashier', 'administrator or a manager', function (array &$c, array &$s): void { global $scenarios; sale($c, function (array &$p) use ($scenarios): void { $p['userId'] = $scenarios[WHOLE]['context']['cashierId']; }); }],
    [WHOLE, 2, 'a user who was deleted', 'not an active user', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'users/') && ($row['roleId'] ?? '') === '00000000-0000-4000-8000-000000000002') { $s[$key]['deletedAt'] = '2026-11-16T09:02:00.000Z'; } } }],
    [WHOLE, 2, 'a user who is no longer active', 'not an active user', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'users/') && ($row['roleId'] ?? '') === '00000000-0000-4000-8000-000000000002') { $s[$key]['status'] = 'inactive'; } } }],
    // -- the lines
    [WHOLE, 2, 'a refund with no lines', 'no lines', function (array &$c, array &$s): void { $c = array_values(array_filter($c, static fn (array $x): bool => $x['table_name'] !== 'sale_items')); }],
    [WHOLE, 2, 'a line that refunds nothing of the sale', 'not a line of the sale', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['refundOfItemId'] = newId(); }); }],
    [WHOLE, 2, 'the same line refunded twice', 'twice', function (array &$c, array &$s): void { $c[] = renamed($c[at($c, 'sale_items')]); }],
    [PARTS, 3, 'more than is left of a line', 'can still be refunded', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['quantity'] = -3; $p['lineTotalCents'] = -30000; }); }],
    [WHOLE, 2, 'a line with another name', 'does not match', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['itemName'] = 'Something else'; }); }],
    [WHOLE, 2, 'a line for another product', 'does not match', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['productId'] = newId(); }); }],
    [WHOLE, 2, 'a line at another price', 'does not match', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['unitPriceCents'] = 5000; $p['lineTotalCents'] = 5000 * $p['quantity']; }); }],
    [WHOLE, 2, 'a cost that is neither the sale\'s nor nothing', 'cost of a line', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['costPriceCents'] = 1234; }); }],
    [MANUAL, 2, 'a manual line with a cost of its own', 'cost of a line', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['costPriceCents'] = 100; }); }],
    [WHOLE, 2, 'lines that do not add up to the subtotal', 'subtotal', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['quantity'] = -1; $p['lineTotalCents'] = -$p['unitPriceCents']; }); }],
    [WHOLE, 2, 'a refund line with a positive quantity', 'quantity', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['quantity'] = 2; $p['lineTotalCents'] = 2 * $p['unitPriceCents']; }); }],
    [WHOLE, 2, 'a refund line whose total has the wrong sign', 'lineTotalCents', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['lineTotalCents'] = -$p['lineTotalCents']; }); }],
    [WHOLE, 2, 'a refund line that is not price times quantity', 'line total', function (array &$c, array &$s): void { line($c, function (array &$p): void { $p['lineTotalCents'] -= 1; }); }],
    [WHOLE, 2, 'a refund that goes twice over what is left of the sale', 'can still be refunded', function (array &$c, array &$s): void { foreach ($c as $change) { if ($change['table_name'] === 'sales' || $change['table_name'] === 'sale_items' || $change['table_name'] === 'stock_movements') { $s[$change['table_name'] . '/' . $change['row_id']] = $change['payload']; } } $c = array_map(static fn (array $x): array => renamed($x), $c); $sale = at($c, 'sales'); $newSaleId = $c[$sale]['row_id']; foreach ($c as $k => $x) { if ($x['table_name'] === 'sale_items') { $c[$k]['payload']['saleId'] = $newSaleId; } } $c[$sale]['payload']['saleNumber'] = 'AGAIN-1'; foreach ($c as $k => $x) { if ($x['table_name'] === 'stock_movements') { $c[$k]['payload']['note'] = 'Refund AGAIN-1'; } } $c[$sale]['payload']['refundOfSaleId'] = $s['sales/' . $c[$sale]['payload']['refundOfSaleId']]['id'] ?? $c[$sale]['payload']['refundOfSaleId']; }],
    // -- the money
    [DISCOUNTED, 2, 'a discount share that is not in proportion', 'in proportion', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['discountCents'] = -1000; $p['totalCents'] = $p['subtotalCents'] + 1000; }); }],
    [DISCOUNTED, 2, 'no discount given back at all', 'in proportion', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['discountCents'] = 0; $p['totalCents'] = $p['subtotalCents']; }); }],
    [DISCOUNTED, 4, 'the last refund without the rounding it should take', 'in proportion', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['discountCents'] += 1; $p['totalCents'] -= 1; }); }],
    [WHOLE, 2, 'cash received on a cash refund', 'cash given back', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['cashReceivedCents'] = -100; }); }],
    // -- the stock
    [WHOLE, 2, 'goods that go back on the shelf with no stock returned', 'must be returned', function (array &$c, array &$s): void { $c = array_values(array_filter($c, static fn (array $x): bool => $x['table_name'] !== 'stock_movements')); }],
    [WHOLE, 2, 'a stock return of the wrong quantity', 'must be returned', function (array &$c, array &$s): void { $c[at($c, 'stock_movements')]['payload']['quantity'] += 1; }],
    [WHOLE, 2, 'a stock return by someone else', 'does not match the refund', function (array &$c, array &$s): void { $c[at($c, 'stock_movements')]['payload']['userId'] = newId(); }],
    [WHOLE, 2, 'a stock return with no person', 'does not match the refund', function (array &$c, array &$s): void { $c[at($c, 'stock_movements')]['payload']['userId'] = null; }],
    [WHOLE, 2, 'a stock movement that is not a return', 'a sale takes stock out', function (array &$c, array &$s): void { $c[at($c, 'stock_movements')]['payload']['movementType'] = 'sale'; }],
    [WHOLE, 2, 'two stock returns of one product', 'does not match the refund', function (array &$c, array &$s): void { $c[] = renamed($c[at($c, 'stock_movements')]); }],
    [PARTS, 3, 'stock returned for goods that are thrown away', 'do not go back on the shelf', function (array &$c, array &$s): void { $c[] = ['table_name' => 'stock_movements', 'row_id' => ($id = newId()), 'local_rev' => 99999, 'updated_at' => '2026-11-16T09:00:00.000Z', 'payload' => ['id' => $id, 'createdAt' => '2026-11-16T09:00:00.000Z', 'updatedAt' => '2026-11-16T09:00:00.000Z', 'deletedAt' => null, 'localRev' => 99999, 'createdByDeviceId' => $c[0]['payload']['createdByDeviceId'], 'syncState' => 'local_only', 'productId' => $c[at($c, 'sale_items')]['payload']['productId'], 'userId' => $c[at($c, 'sales')]['payload']['userId'], 'movementType' => 'return', 'quantity' => 2, 'note' => 'Refund ' . $c[at($c, 'sales')]['payload']['saleNumber']]]; }],
    [WHOLE, 2, 'stock returned for a product that is not in this shop any more', 'not in this shop', function (array &$c, array &$s): void { $productId = $c[at($c, 'stock_movements')]['payload']['productId']; $s['products/' . $productId]['deletedAt'] = '2026-11-16T09:03:00.000Z'; }],
    [WHOLE, 2, 'stock returned for a product that is not refunded', 'not refunded', function (array &$c, array &$s): void { $other = null; foreach ($s as $key => $row) { if (str_starts_with($key, 'products/') && $row['id'] !== $c[at($c, 'stock_movements')]['payload']['productId'] && $row['id'] !== $c[at($c, 'stock_movements', 1)]['payload']['productId']) { $other = $row['id']; } } $c[] = renamed($c[at($c, 'stock_movements')]); $c[count($c) - 1]['payload']['productId'] = $other ?? newId(); }],
    // -- the payment
    [WHOLE, 2, 'a payment record on a cash refund', 'no payment record', function (array &$c, array &$s): void { $c[] = ['table_name' => 'payment_records', 'row_id' => ($id = newId()), 'local_rev' => 99998, 'updated_at' => '2026-11-16T09:00:00.000Z', 'payload' => ['id' => $id, 'createdAt' => '2026-11-16T09:00:00.000Z', 'updatedAt' => '2026-11-16T09:00:00.000Z', 'deletedAt' => null, 'localRev' => 99998, 'createdByDeviceId' => $c[0]['payload']['createdByDeviceId'], 'syncState' => 'local_only', 'saleId' => $c[at($c, 'sales')]['row_id'], 'method' => 'mpesa_manual', 'amountCents' => -100, 'referenceNote' => 'x', 'status' => 'refunded']]; }],
    [PARTS, 2, 'an M-Pesa refund with no payment record', 'exactly one payment', function (array &$c, array &$s): void { $c = array_values(array_filter($c, static fn (array $x): bool => $x['table_name'] !== 'payment_records')); }],
    [PARTS, 2, 'an M-Pesa refund with two payment records', 'exactly one payment', function (array &$c, array &$s): void { $c[] = renamed($c[at($c, 'payment_records')]); }],
    [PARTS, 2, 'a positive payment amount', 'amountCents', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['amountCents'] = abs($c[at($c, 'payment_records')]['payload']['amountCents']); }],
    [PARTS, 2, 'a payment amount that is not the refund', 'does not match the refund', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['amountCents'] -= 1; }],
    [PARTS, 2, 'a refund payment that is not marked refunded', 'amountCents', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['status'] = 'paid'; }],
    [PARTS, 2, 'a payment note that does not name the sale', 'does not match the refund', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['referenceNote'] = 'Refund of something else'; }],
    [PARTS, 2, 'a payment by another method', 'does not match the refund', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['method'] = 'paystack'; }],
    [WHOLE, 2, 'a refund that is more than the sale once an earlier refund is counted', 'more than the sale', function (array &$c, array &$s): void { craftEarlier($s, originalSale($s), 1, 0, null); }],
    [MANUAL, 2, 'a manual line that keeps a cost the app would not write', 'manual line', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'sale_items/') && $row['productId'] === null) { $s[$key]['costPriceCents'] = 50; } } line($c, function (array &$p): void { $p['costPriceCents'] = 50; }); }],
    [SPLIT, 2, 'a refund of a split sale with no cash share', 'cash given back', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['cashReceivedCents'] = null; }); }],
    [SPLIT, 2, 'a cash share that is not the proportion', 'cash given back', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['cashReceivedCents'] -= 1; }); }],
    [SPLIT, 2, 'a positive cash share', 'cashReceivedCents', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['cashReceivedCents'] = 2500; }); }],
    [SPLIT, 3, 'the last cash share without the rounding it should take', 'cash given back', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['cashReceivedCents'] += 1; }); }],
    [SPLIT, 2, 'a split refund that pays back no discount share', 'in proportion', function (array &$c, array &$s): void { sale($c, function (array &$p): void { $p['discountCents'] = 0; $p['totalCents'] = $p['subtotalCents']; }); }],
    [WHOLE, 2, 'goods with no cost price and a stock return of the wrong quantity', 'not the quantity refunded', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'sale_items/')) { $s[$key]['costPriceCents'] = 0; } } foreach ($c as $k => $x) { if ($x['table_name'] === 'sale_items') { $c[$k]['payload']['costPriceCents'] = 0; } } $c[at($c, 'stock_movements')]['payload']['quantity'] += 1; }],
    // -- anything else
    [WHOLE, 2, 'a record that belongs to no refund', 'does not belong', function (array &$c, array &$s): void { $c[] = renamed($c[at($c, 'sale_items')]); $c[count($c) - 1]['payload']['saleId'] = newId(); }],
    [WHOLE, 2, 'a push that writes a product', 'may not write', function (array &$c, array &$s): void { $c[] = ['table_name' => 'products', 'row_id' => newId(), 'local_rev' => 99997, 'updated_at' => '2026-11-16T09:00:00.000Z', 'payload' => ['id' => newId()]]; }],
];

// Refunds the app would also write, in states the usual scenarios do not reach. Each must be ACCEPTED.
$variants = [
    [WHOLE, 2, 'an administrator refunding', function (array &$c, array &$s): void { $admin = adminId($s); sale($c, function (array &$p) use ($admin): void { $p['userId'] = $admin; }); $c[at($c, 'stock_movements')]['payload']['userId'] = $admin; $c[at($c, 'stock_movements', 1)]['payload']['userId'] = $admin; }],
    [WHOLE, 2, 'a refund after an earlier refund of the same sale was deleted', function (array &$c, array &$s): void { craftEarlier($s, originalSale($s), 45050, 0, null, 'paid', '2026-11-16T08:30:00.000Z'); }],
    [WHOLE, 2, 'a refund after an earlier refund of the same sale that is not paid', function (array &$c, array &$s): void { craftEarlier($s, originalSale($s), 45050, 0, null, 'pending'); }],
    [WHOLE, 2, 'a refund after an earlier refund that was cancelled', function (array &$c, array &$s): void { craftEarlier($s, originalSale($s), 45050, 0, null, 'cancelled'); }],
    [DISCOUNTED, 2, 'an earlier refund that took more of the discount than its share: this one gives none back', function (array &$c, array &$s): void { craftEarlier($s, originalSale($s), 1, 9000, null); sale($c, function (array &$p): void { $p['discountCents'] = 0; $p['totalCents'] = $p['subtotalCents']; }); }],
    [SPLIT, 2, 'an earlier refund that took more of the cash than its share: this one gives none back', function (array &$c, array &$s): void { craftEarlier($s, originalSale($s), 1, 0, 9000); sale($c, function (array &$p): void { $p['cashReceivedCents'] = 0; }); }],
    [SPLIT, 2, 'an earlier refund that took too little of the cash: this one never gives back more cash than its own amount', function (array &$c, array &$s): void { craftEarlier($s, originalSale($s), 30000, 0, 0); sale($c, function (array &$p): void { $p['discountCents'] = -4000; $p['totalCents'] = $p['subtotalCents'] + 4000; $p['cashReceivedCents'] = $p['totalCents']; }); $c[at($c, 'payment_records')]['payload']['amountCents'] = $c[at($c, 'sales')]['payload']['totalCents']; }],
    [MANUAL, 2, 'a manual line whose sale cost was not nothing: the refund writes nothing, as the app does', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'sale_items/') && $row['productId'] === null) { $s[$key]['costPriceCents'] = 50; } } }],
    [WHOLE, 2, 'goods with no cost price put back on the shelf', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'sale_items/')) { $s[$key]['costPriceCents'] = 0; } } foreach ($c as $k => $x) { if ($x['table_name'] === 'sale_items') { $c[$k]['payload']['costPriceCents'] = 0; } } }],
    [WHOLE, 2, 'goods with no cost price, whether or not stock is returned', function (array &$c, array &$s): void { foreach ($s as $key => $row) { if (str_starts_with($key, 'sale_items/')) { $s[$key]['costPriceCents'] = 0; } } foreach ($c as $k => $x) { if ($x['table_name'] === 'sale_items') { $c[$k]['payload']['costPriceCents'] = 0; } } $c = array_values(array_filter($c, static fn (array $x): bool => $x['table_name'] !== 'stock_movements')); }],
];
$variantsAccepted = 0;
foreach ($variants as [$name, $stageNumber, $label, $mutate]) {
    $scenario = $scenarios[$name];
    $store = newShop($scenario['context']);
    $changes = null;
    foreach ($scenario['stages'] as $stage) {
        if ($stage['stage'] === $stageNumber) {
            $changes = $stage['changes'];
            break;
        }
        remember($store, $stage['changes']);
    }
    $mutate($changes, $store);
    expectAccepted("$name stage $stageNumber: $label", static function () use ($changes, $scenario, $store): void {
        runGuard($changes, $scenario['context']['deviceId'], $store);
    }, $failures);
    $variantsAccepted++;
}

$refused = 0;
foreach ($mutations as [$name, $stageNumber, $label, $fragment, $mutate]) {
    $scenario = $scenarios[$name];
    $store = newShop($scenario['context']);
    $changes = null;
    foreach ($scenario['stages'] as $stage) {
        if ($stage['stage'] === $stageNumber) {
            $changes = $stage['changes'];
            break;
        }
        remember($store, $stage['changes']);
    }
    if ($changes === null) {
        $failures[] = "No stage $stageNumber in $name for: $label";
        continue;
    }
    $mutate($changes, $store);
    expectRefused("$name stage $stageNumber: $label", $fragment, static function () use ($changes, $scenario, $store): void {
        runGuard($changes, $scenario['context']['deviceId'], $store);
    }, $failures);
    $refused++;
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Refund guard tests passed: $accepted real-app record sets accepted, $variantsAccepted unusual but valid refunds accepted, $refused kinds of bad refund record sets refused.\n";
