<?php

declare(strict_types=1);

// The sync guard must (1) accept every record set the REAL app produces - the fixtures are written
// by the app's own tests, tests/fixtures/sync_guard/sale_scenarios.json - and (2) refuse each kind of
// broken or hostile record set below. No database or web server needed.

require __DIR__ . '/../app/Services/SyncGuard.php';

use Platform\Services\SyncGuard;
use Platform\Services\SyncGuardViolation;

$data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/sync_guard/sale_scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
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
    SyncGuard::check($changes, $deviceId, $stored, $storedItems);
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
foreach ($scenarios as $name => $scenario) {
    $store = newShop($scenario['context']);
    foreach ($scenario['stages'] as $stage) {
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

$mutations = [
    // [scenario, stage, what is wrong, expected reason, mutation]
    ['cash_sale_two_lines', 1, 'an extra field on a sale', 'wrong fields', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['isAdmin'] = true; }],
    ['cash_sale_two_lines', 1, 'a missing field on a sale', 'wrong fields', function (array &$c, array &$s): void { unset($c[at($c, 'sales')]['payload']['discountCents']); }],
    ['cash_sale_two_lines', 1, 'a quantity sent as text', 'quantity', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['quantity'] = '2'; }],
    ['cash_sale_two_lines', 1, 'a negative price', 'unitPriceCents', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['unitPriceCents'] = -100; }],
    ['cash_sale_two_lines', 1, 'an absurd price', 'unitPriceCents', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['unitPriceCents'] = 99_000_000_000; }],
    ['cash_sale_two_lines', 1, 'a fractional quantity', 'quantity', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['quantity'] = 1.5; }],
    ['cash_sale_two_lines', 1, 'zero quantity', 'quantity', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['quantity'] = 0; }],
    ['cash_sale_two_lines', 1, 'a line total that is not price x quantity', 'line total', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['lineTotalCents'] += 1; }],
    ['cash_sale_two_lines', 1, 'a subtotal that is not the sum of the lines', 'subtotal', function (array &$c, array &$s): void { $i = at($c, 'sales'); $c[$i]['payload']['subtotalCents'] += 100; $c[$i]['payload']['totalCents'] += 100; }],
    ['cash_sale_discount', 1, 'a total that ignores the discount', 'do not add up', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['totalCents'] += 1; }],
    ['cash_sale_discount', 1, 'a discount bigger than the subtotal', 'do not add up', function (array &$c, array &$s): void { $i = at($c, 'sales'); $c[$i]['payload']['discountCents'] = $c[$i]['payload']['subtotalCents'] + 1; $c[$i]['payload']['totalCents'] = 0; }],
    ['cash_sale_two_lines', 1, 'an unknown payment method', 'paymentMethod', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['paymentMethod'] = 'bitcoin'; }],
    ['cash_sale_two_lines', 1, 'an unknown sale type', 'saleType', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['saleType'] = 'free'; }],
    ['cash_sale_two_lines', 1, 'a sale from a cashier who is not in the shop', 'cashier', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['userId'] = '00000000-0000-4000-8000-0000000000aa'; }],
    ['cash_sale_two_lines', 1, 'a line for a product not in the shop', 'product', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['productId'] = '00000000-0000-4000-8000-0000000000bb'; }],
    ['cash_sale_two_lines', 1, 'a sale with no lines', 'no lines', function (array &$c, array &$s): void { foreach (array_reverse(array_keys($c)) as $i) { if ($c[$i]['table_name'] === 'sale_items') { unset($c[$i]); } } $c = array_values($c); }],
    ['cash_sale_two_lines', 1, 'a line that no stock movement matches', 'stock movements', function (array &$c, array &$s): void { unset($c[at($c, 'stock_movements')]); $c = array_values($c); }],
    ['cash_sale_two_lines', 1, 'a stock movement of the wrong size', 'stock movements', function (array &$c, array &$s): void { $c[at($c, 'stock_movements')]['payload']['quantity'] = -5; }],
    ['cash_sale_two_lines', 1, 'a stock movement for a different product', 'stock movements', function (array &$c, array &$s): void { $c[at($c, 'stock_movements', 0)]['payload']['productId'] = $c[at($c, 'sale_items', 1)]['payload']['productId']; }],
    ['cash_sale_two_lines', 1, 'stock that goes UP on a sale', 'negative', function (array &$c, array &$s): void { $c[at($c, 'stock_movements')]['payload']['quantity'] = 2; }],
    ['cash_sale_two_lines', 1, 'a stock movement that is not a sale or return', 'movementType', function (array &$c, array &$s): void { $c[at($c, 'stock_movements')]['payload']['movementType'] = 'adjustment'; }],
    ['cash_sale_two_lines', 1, 'a cash sale with a payment record', 'cash sale has no payment', function (array &$c, array &$s): void { $c[] = ['table_name' => 'payment_records', 'row_id' => '00000000-0000-4000-8000-000000000001', 'local_rev' => 99, 'updated_at' => $c[0]['updated_at'], 'payload' => ['id' => '00000000-0000-4000-8000-000000000001', 'createdAt' => $c[0]['payload']['createdAt'], 'updatedAt' => $c[0]['payload']['updatedAt'], 'deletedAt' => null, 'localRev' => 99, 'createdByDeviceId' => $c[0]['payload']['createdByDeviceId'], 'syncState' => 'local_only', 'saleId' => $c[0]['payload']['id'], 'method' => 'mpesa', 'amountCents' => 100, 'referenceNote' => null, 'status' => 'paid']]; }],
    ['mpesa_manual_sale', 1, 'an M-Pesa sale without its payment record', 'exactly one payment', function (array &$c, array &$s): void { unset($c[at($c, 'payment_records')]); $c = array_values($c); }],
    ['mpesa_manual_sale', 1, 'a payment of the wrong amount', 'payment record does not match', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['amountCents'] -= 1; }],
    ['mpesa_manual_sale', 1, 'a payment of a different method', 'payment method', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['method'] = 'mpesa'; }],
    ['mpesa_manual_sale', 1, 'a payment that is not yet paid', 'payment record does not match', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['status'] = 'initiated'; }],
    ['mpesa_manual_sale', 1, 'cash received on an M-Pesa sale', 'cash received', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['cashReceivedCents'] = 100; }],
    ['mpesa_manual_sale', 1, 'a bad customer phone', 'phone', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['customerPhone'] = '<script>'; }],
    ['paystack_pending_then_paid', 1, 'an online sale that starts out already paid', 'must start as pending', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['status'] = 'paid'; }],
    ['paystack_pending_then_paid', 1, 'an online payment that starts out paid', 'online payment record', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['status'] = 'paid'; }],
    ['paystack_pending_then_paid', 1, 'an online payment with no reference', 'online payment record', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['referenceNote'] = null; }],
    ['paystack_split_paid', 1, 'a split sale whose online part is wrong', 'online payment record', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['amountCents'] = 50100; }],
    ['paystack_split_paid', 1, 'a split sale whose cash covers the whole total', 'cash part', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['cashReceivedCents'] = 50100; }],
    ['cash_sale_two_lines', 1, 'a record written by another device', 'not created by this device', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['createdByDeviceId'] = 'someone-else'; }],
    ['cash_sale_two_lines', 1, 'a deleted record', 'cannot delete', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['deletedAt'] = '2026-11-06T21:50:11.000000Z'; }],
    ['cash_sale_two_lines', 1, 'an unknown sync state', 'syncState', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['syncState'] = 'hacked'; }],
    ['cash_sale_two_lines', 1, 'an id that is not a UUID', 'valid id', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['id'] = "1'; DROP TABLE sales;--"; }],
    ['cash_sale_two_lines', 1, 'a time that is not a time', 'valid time', function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['updatedAt'] = 'yesterday'; }],
    ['cash_sale_two_lines', 1, 'control characters in a name', 'valid text', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['itemName'] = "Wid\x00get"; }],
    ['cash_sale_two_lines', 1, 'a very long name', 'valid text', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['itemName'] = str_repeat('x', 201); }],
    ['cash_sale_two_lines', 1, 'an empty item name', 'valid text', function (array &$c, array &$s): void { $c[at($c, 'sale_items')]['payload']['itemName'] = '   '; }],
    ['manual_line_sale', 1, 'a free manual line', 'manual line needs a price', function (array &$c, array &$s): void { $i = at($c, 'sale_items', 0); $c[$i]['payload']['unitPriceCents'] = 0; $c[$i]['payload']['lineTotalCents'] = 0; }],
    ['cash_sale_two_lines', 1, 'a write to the products table', "may not write to 'products'", function (array &$c, array &$s): void { $c[0]['table_name'] = 'products'; }],
    ['cash_sale_two_lines', 1, 'a write to the users table', "may not write to 'users'", function (array &$c, array &$s): void { $c[0]['table_name'] = 'users'; }],
    ['cash_sale_two_lines', 1, 'a sale line whose sale is not in the push', 'does not belong', function (array &$c, array &$s): void { unset($c[at($c, 'sales')]); $c = array_values($c); }],
    ['cash_sale_two_lines', 1, 'the same record twice', 'twice', function (array &$c, array &$s): void { $c[] = $c[at($c, 'sale_items')]; }],
    ['cash_sale_two_lines', 1, 'too many changes in one push', 'at most', function (array &$c, array &$s): void { $c = array_merge($c, array_fill(0, 201, $c[0])); }],
    ['cash_sale_two_lines', 1, 'a change that is not a record', 'not a record', function (array &$c, array &$s): void { $c[] = 'nope'; }],
    // an existing (pending) online sale
    ['paystack_pending_then_paid', 2, 'a paid sale whose total was changed', 'cannot change', function (array &$c, array &$s): void { $i = at($c, 'sales'); $c[$i]['payload']['totalCents'] += 1; $c[$i]['payload']['subtotalCents'] += 1; }],
    ['paystack_pending_then_paid', 2, 'a paid sale whose payment amount was changed', 'cannot change', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['amountCents'] = 1; }],
    ['paystack_pending_then_paid', 2, 'a paid sale whose payment is still "initiated"', 'payment status', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['status'] = 'initiated'; }],
    ['paystack_pending_then_paid', 2, 'a paid sale with no payment update', 'payment record must be updated', function (array &$c, array &$s): void { unset($c[at($c, 'payment_records')]); $c = array_values($c); }],
    ['paystack_pending_then_paid', 2, 'a paid sale that also moves stock', 'stock returned', function (array &$c, array &$s): void { $c[] = ['table_name' => 'stock_movements', 'row_id' => '00000000-0000-4000-8000-000000000002', 'local_rev' => 98, 'updated_at' => $c[0]['updated_at'], 'payload' => ['id' => '00000000-0000-4000-8000-000000000002', 'createdAt' => $c[0]['payload']['createdAt'], 'updatedAt' => $c[0]['payload']['updatedAt'], 'deletedAt' => null, 'localRev' => 98, 'createdByDeviceId' => $c[0]['payload']['createdByDeviceId'], 'syncState' => 'local_only', 'productId' => '00000000-0000-4000-8000-0000000000bb', 'userId' => null, 'movementType' => 'return', 'quantity' => 5, 'note' => 'free stock']]; }],
    ['paystack_pending_then_paid', 2, 'a sale sent back to pending', "cannot go from", function (array &$c, array &$s): void { $c[at($c, 'sales')]['payload']['status'] = 'pending'; }],
    ['paystack_pending_then_paid', 2, 'a sale that is already paid being changed again', 'cannot go from', function (array &$c, array &$s): void { $key = 'sales/' . $c[at($c, 'sales')]['payload']['id']; $s[$key]['status'] = 'paid'; }],
    ['paystack_pending_then_paid', 2, 'a new line added to an existing sale', 'does not belong', function (array &$c, array &$s): void { $c[] = ['table_name' => 'sale_items', 'row_id' => '00000000-0000-4000-8000-000000000003', 'local_rev' => 97, 'updated_at' => $c[0]['updated_at'], 'payload' => ['id' => '00000000-0000-4000-8000-000000000003', 'createdAt' => $c[0]['payload']['createdAt'], 'updatedAt' => $c[0]['payload']['updatedAt'], 'deletedAt' => null, 'localRev' => 97, 'createdByDeviceId' => $c[0]['payload']['createdByDeviceId'], 'syncState' => 'local_only', 'saleId' => $c[0]['payload']['id'], 'productId' => null, 'itemName' => 'Sneaky', 'quantity' => 1, 'unitPriceCents' => 100, 'costPriceCents' => 0, 'lineTotalCents' => 100]]; }],
    ['paystack_pending_then_cancelled', 2, 'a cancellation that returns too much stock', 'stock returned', function (array &$c, array &$s): void { $c[at($c, 'stock_movements')]['payload']['quantity'] = 3; }],
    ['paystack_pending_then_cancelled', 2, 'a cancellation that returns nothing', 'stock returned', function (array &$c, array &$s): void { unset($c[at($c, 'stock_movements')]); $c = array_values($c); }],
    ['paystack_pending_then_cancelled', 2, 'a cancelled sale whose payment is "paid"', 'payment status', function (array &$c, array &$s): void { $c[at($c, 'payment_records')]['payload']['status'] = 'paid'; }],
    ['paystack_pending_then_cancelled', 2, 'a return that is really a sale movement', 'does not belong', function (array &$c, array &$s): void { $i = at($c, 'stock_movements'); $c[$i]['payload']['movementType'] = 'sale'; $c[$i]['payload']['quantity'] = -2; }],
];

$refused = 0;
foreach ($mutations as [$name, $stageNo, $what, $reason, $mutate]) {
    $scenario = $scenarios[$name];
    $store = newShop($scenario['context']);
    foreach ($scenario['stages'] as $stage) {
        if ($stage['stage'] < $stageNo) {
            remember($store, $stage['changes']);
        }
    }
    $changes = $scenario['stages'][$stageNo - 1]['changes'];
    $mutate($changes, $store);
    expectRefused("$name stage $stageNo: $what", $reason, static function () use ($changes, $scenario, $store): void {
        runGuard($changes, $scenario['context']['deviceId'], $store);
    }, $failures);
    $refused++;
}

if ($failures) {
    fwrite(STDERR, "SYNC GUARD TEST FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Sync guard tests passed: $accepted real-app record sets accepted, $refused kinds of bad record sets refused.\n";
