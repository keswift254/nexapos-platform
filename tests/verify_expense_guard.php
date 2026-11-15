<?php

declare(strict_types=1);

// A lite till may add and delete EXPENSES - nothing else new. This checks the guard's expense rules (app/Services/SyncGuard.php):
//  1. every expense record the real app produces (tests/fixtures/sync_guard/expense_scenarios.json, written by the app's own
//     test/tool/expense_contract_fixtures_test.dart) is accepted, including deleting one;
//  2. deleting an expense that ANOTHER device created is accepted (a manager on the iPad removing an entry made on the main PC);
//  3. each kind of bad expense record is refused, for the right reason.
// No database or web server needed.
//
//   php tests/verify_expense_guard.php

require __DIR__ . '/../app/Services/SyncGuard.php';

use Platform\Services\SyncGuard;
use Platform\Services\SyncGuardViolation;

$data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/sync_guard/expense_scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
$scenarios = [];
foreach ($data['scenarios'] as $scenario) {
    $scenarios[$scenario['name']] = $scenario;
}

/** A fake shop: stored records keyed "table/id", as the platform's sync_changes would hold them. */
function newShop(array $context): array
{
    $store = [];
    foreach ($context['users'] as $payload) {
        $store['users/' . $payload['id']] = $payload;
    }
    return $store;
}

function runGuard(array $changes, string $deviceId, array $store): void
{
    $stored = static fn (string $table, string $id): ?array => $store[$table . '/' . $id] ?? null;
    SyncGuard::check($changes, $deviceId, $stored, static fn (string $saleId): array => []);
}

function remember(array &$store, array $changes): void
{
    foreach ($changes as $change) {
        $store[$change['table_name'] . '/' . $change['row_id']] = $change['payload'];
    }
}

$failures = [];
$checks = 0;
function expectAccepted(string $label, callable $run, array &$failures): void
{
    global $checks;
    $checks++;
    try {
        $run();
    } catch (SyncGuardViolation $e) {
        $failures[] = "REFUSED a good record set ($label): " . $e->getMessage();
    }
}
function expectRefused(string $label, string $fragment, callable $run, array &$failures): void
{
    global $checks;
    $checks++;
    try {
        $run();
        $failures[] = "ACCEPTED a bad record set ($label)";
    } catch (SyncGuardViolation $e) {
        if ($fragment !== '' && stripos($e->getMessage(), $fragment) === false) {
            $failures[] = "Refused ($label) for the wrong reason: '{$e->getMessage()}' (expected to mention '$fragment')";
        }
    }
}

// ---- 1. everything the real app produces is accepted --------------------------------------------------------------
foreach ($scenarios as $name => $scenario) {
    $store = newShop($scenario['context']);
    foreach ($scenario['stages'] as $stage) {
        expectAccepted("$name stage {$stage['stage']}", static function () use ($stage, $scenario, $store): void {
            runGuard($stage['changes'], $scenario['context']['deviceId'], $store);
        }, $failures);
        remember($store, $stage['changes']);
    }
}

// The app's own device wrote these; the till that pushes them has its own device id, so rewrite who made them.
function asTill(array $changes, string $tillDevice): array
{
    foreach ($changes as $i => $change) {
        $changes[$i]['payload']['createdByDeviceId'] = $tillDevice;
    }
    return $changes;
}
const TILL = 'lite-till-device-1';

/** A scenario's stage as the till would push it (its own device id on the records). */
function stageAsTill(array $scenarios, string $name, int $stage): array
{
    return asTill($scenarios[$name]['stages'][$stage - 1]['changes'], TILL);
}
function shopFor(array $scenarios, string $name): array
{
    return newShop($scenarios[$name]['context']);
}

expectAccepted('a till adding an expense with a user and a note', static function () use ($scenarios): void {
    runGuard(stageAsTill($scenarios, 'expense_with_user_and_note', 1), TILL, shopFor($scenarios, 'expense_with_user_and_note'));
}, $failures);
expectAccepted('a till adding an expense with no user and no note', static function () use ($scenarios): void {
    runGuard(stageAsTill($scenarios, 'expense_without_user_or_note', 1), TILL, shopFor($scenarios, 'expense_without_user_or_note'));
}, $failures);

// The edges of the allowed dates: the first day of 2020, today and tomorrow are fine (a till's clock can run a day ahead);
// the day before 2020 and a few days ahead are not.
foreach (['2020-01-01', gmdate('Y-m-d'), gmdate('Y-m-d', time() + 86400)] as $okDate) {
    expectAccepted("an expense dated $okDate", static function () use ($scenarios, $okDate): void {
        runGuard(badNew($scenarios, static function (array &$p) use ($okDate): void { $p['expenseDate'] = $okDate; }), TILL, shopFor($scenarios, 'expense_with_user_and_note'));
    }, $failures);
}
foreach ([gmdate('Y-m-d', time() + 86400 * 4), gmdate('Y-m-d', time() + 86400 * 30), '2019-12-31'] as $badDate) {
    expectRefused("an expense dated $badDate", 'expense date must be between', static function () use ($scenarios, $badDate): void {
        runGuard(badNew($scenarios, static function (array &$p) use ($badDate): void { $p['expenseDate'] = $badDate; }), TILL, shopFor($scenarios, 'expense_with_user_and_note'));
    }, $failures);
}

// ---- 2. deleting: its own expense (the apps and the sync do the same: a device only sends what it entered) ------------
$deleteScenario = 'expense_created_then_deleted';
$store = shopFor($scenarios, $deleteScenario);
remember($store, stageAsTill($scenarios, $deleteScenario, 1));
expectAccepted('a till deleting an expense it made', static function () use ($scenarios, $deleteScenario, $store): void {
    runGuard(stageAsTill($scenarios, $deleteScenario, 2), TILL, $store);
}, $failures);

// An expense another device (the main PC) entered cannot be deleted by the till - the sync only takes a record from the device
// that created it - whichever way the deletion is written.
$otherDevice = 'main-pc-device-7';
$storeOther = shopFor($scenarios, $deleteScenario);
remember($storeOther, asTill($scenarios[$deleteScenario]['stages'][0]['changes'], $otherDevice));
expectRefused('a till deleting an expense another device made (written as the till\'s own record)', 'entered on another device', static function () use ($scenarios, $deleteScenario, $storeOther): void {
    runGuard(stageAsTill($scenarios, $deleteScenario, 2), TILL, $storeOther);
}, $failures);
expectRefused('a till deleting an expense another device made (keeping that device on the record)', 'not created by this device', static function () use ($scenarios, $deleteScenario, $storeOther, $otherDevice): void {
    runGuard(asTill($scenarios[$deleteScenario]['stages'][1]['changes'], $otherDevice), TILL, $storeOther);
}, $failures);

// (badNew is defined just below; the PHP functions are available from the start of the file)
// ---- 3. bad new expenses are refused --------------------------------------------------------------------------------
/** The first stage of a scenario as the till pushes it, with [$mutate] applied to the (first) expense payload. */
function badNew(array $scenarios, callable $mutate, string $scenario = 'expense_with_user_and_note'): array
{
    $changes = stageAsTill($scenarios, $scenario, 1);
    $mutate($changes[0]['payload']);
    $changes[0]['row_id'] = $changes[0]['payload']['id'];
    return $changes;
}

$bad = [
    // [what is wrong, expected reason, mutation of the payload]
    ['an extra field', 'wrong fields', static function (array &$p): void { $p['isAdmin'] = true; }],
    ['a missing field', 'wrong fields', static function (array &$p): void { unset($p['title']); }],
    ['an amount of zero', 'amountCents', static function (array &$p): void { $p['amountCents'] = 0; }],
    ['a negative amount', 'amountCents', static function (array &$p): void { $p['amountCents'] = -500; }],
    ['a fractional amount', 'amountCents', static function (array &$p): void { $p['amountCents'] = 10.5; }],
    ['an amount sent as text', 'amountCents', static function (array &$p): void { $p['amountCents'] = '100'; }],
    ['an absurd amount', 'amountCents', static function (array &$p): void { $p['amountCents'] = 100_000_001; }],
    ['the largest amount allowed is fine, one more is not', 'amountCents', static function (array &$p): void { $p['amountCents'] = 100_000_000 + 1; }],
    ['an empty name', 'valid text', static function (array &$p): void { $p['title'] = '   '; }],
    ['a name with spaces round it', 'start or end with spaces', static function (array &$p): void { $p['title'] = ' Transport'; }],
    ['a name with trailing spaces', 'start or end with spaces', static function (array &$p): void { $p['title'] = 'Transport '; }],
    ['a very long name', 'valid text', static function (array &$p): void { $p['title'] = str_repeat('x', 201); }],
    ['control characters in the name', 'valid text', static function (array &$p): void { $p['title'] = "Trans\x00port"; }],
    ['a name that is not text', 'valid text', static function (array &$p): void { $p['title'] = 123; }],
    ['a null name', 'valid text', static function (array &$p): void { $p['title'] = null; }],
    ['a blank note (should be null)', 'valid text', static function (array &$p): void { $p['note'] = '  '; }],
    ['an empty-string note (should be null)', 'valid text', static function (array &$p): void { $p['note'] = ''; }],
    ['a note with spaces round it', 'must not start or end with spaces', static function (array &$p): void { $p['note'] = ' hello'; }],
    ['a very long note', 'valid text', static function (array &$p): void { $p['note'] = str_repeat('n', 501); }],
    ['a note that is not text', 'valid text', static function (array &$p): void { $p['note'] = 5; }],
    ['a date that is not a date', 'invalid expense date', static function (array &$p): void { $p['expenseDate'] = 'yesterday'; }],
    ['a date that does not exist', 'invalid expense date', static function (array &$p): void { $p['expenseDate'] = '2026-02-30'; }],
    ['a month 13', 'invalid expense date', static function (array &$p): void { $p['expenseDate'] = '2026-13-01'; }],
    ['a date written the other way round', 'invalid expense date', static function (array &$p): void { $p['expenseDate'] = '05/11/2026'; }],
    ['a date with a time', 'invalid expense date', static function (array &$p): void { $p['expenseDate'] = '2026-11-05T10:00:00Z'; }],
    ['a date that is not text', 'invalid expense date', static function (array &$p): void { $p['expenseDate'] = 20261105; }],
    ['a date before 2020', 'expense date must be between', static function (array &$p): void { $p['expenseDate'] = '2019-12-31'; }],
    ['a date far in the future', 'expense date must be between', static function (array &$p): void { $p['expenseDate'] = '2099-01-01'; }],
    ['a user id that is not an id', 'valid id', static function (array &$p): void { $p['userId'] = "1'; DROP TABLE expenses;--"; }],
    ['a user who is not in the shop', 'not a user of this shop', static function (array &$p): void { $p['userId'] = '00000000-0000-4000-8000-0000000000aa'; }],
    ['a record written by another device', 'not created by this device', static function (array &$p): void { $p['createdByDeviceId'] = 'someone-else'; }],
    ['a new expense already deleted', 'already deleted', static function (array &$p): void { $p['deletedAt'] = $p['updatedAt']; }],
    ['different created and updated times', 'same created and updated time', static function (array &$p): void { $p['updatedAt'] = '2026-11-13T12:00:00.000000Z'; }],
    ['an unknown sync state', 'syncState', static function (array &$p): void { $p['syncState'] = 'hacked'; }],
    ['an id that is not a UUID', 'valid id', static function (array &$p): void { $p['id'] = 'not-an-id'; }],
    ['a time that is not a time', 'valid time', static function (array &$p): void { $p['createdAt'] = 'yesterday'; }],
    ['a revision of zero', 'localRev', static function (array &$p): void { $p['localRev'] = 0; }],
    ['a revision sent as text', 'localRev', static function (array &$p): void { $p['localRev'] = '5'; }],
];
foreach ($bad as [$what, $reason, $mutate]) {
    expectRefused("new expense: $what", $reason, static function () use ($scenarios, $mutate): void {
        runGuard(badNew($scenarios, $mutate), TILL, shopFor($scenarios, 'expense_with_user_and_note'));
    }, $failures);
}

// A user who has since been deleted is not a user of the shop.
expectRefused('new expense: by a deleted user', 'not a user of this shop', static function () use ($scenarios): void {
    $store = shopFor($scenarios, 'expense_with_user_and_note');
    foreach ($store as $key => $row) {
        $store[$key]['deletedAt'] = '2026-11-01T00:00:00.000000Z';
    }
    runGuard(stageAsTill($scenarios, 'expense_with_user_and_note', 1), TILL, $store);
}, $failures);

expectRefused('the same expense twice in one push', 'twice', static function () use ($scenarios): void {
    $changes = stageAsTill($scenarios, 'expense_with_user_and_note', 1);
    $changes[] = $changes[0];
    runGuard($changes, TILL, shopFor($scenarios, 'expense_with_user_and_note'));
}, $failures);

expectRefused('an expense mixed with a write to a table a till may not write', "may not write to 'products'", static function () use ($scenarios): void {
    $changes = stageAsTill($scenarios, 'expense_with_user_and_note', 1);
    $changes[] = ['table_name' => 'products', 'row_id' => '00000000-0000-4000-8000-000000000001', 'local_rev' => 9, 'updated_at' => $changes[0]['updated_at'], 'payload' => ['id' => '00000000-0000-4000-8000-000000000001']];
    runGuard($changes, TILL, shopFor($scenarios, 'expense_with_user_and_note'));
}, $failures);
expectRefused('an expense mixed with a write to users', "may not write to 'users'", static function () use ($scenarios): void {
    $changes = stageAsTill($scenarios, 'expense_with_user_and_note', 1);
    $changes[0]['table_name'] = 'users';
    runGuard($changes, TILL, shopFor($scenarios, 'expense_with_user_and_note'));
}, $failures);

// ---- 4. bad deletions are refused -------------------------------------------------------------------------------------
/** The deletion stage as the till would push it, with [$mutate] applied to the payload; the shop holds the live expense. */
function badDelete(array $scenarios, callable $mutate, ?callable $mutateStored = null): void
{
    $name = 'expense_created_then_deleted';
    $store = shopFor($scenarios, $name);
    remember($store, stageAsTill($scenarios, $name, 1));
    if ($mutateStored !== null) {
        foreach ($store as $key => $row) {
            if (str_starts_with($key, 'expenses/')) {
                $mutateStored($store[$key]);
            }
        }
    }
    $changes = stageAsTill($scenarios, $name, 2);
    $mutate($changes[0]['payload']);
    $changes[0]['row_id'] = $changes[0]['payload']['id'];
    runGuard($changes, TILL, $store);
}

$badDeletes = [
    ['the amount changed too', 'amountCents of an existing expense cannot change', static function (array &$p): void { $p['amountCents'] = 1; }],
    ['the name changed too', 'title of an existing expense cannot change', static function (array &$p): void { $p['title'] = 'Something else'; }],
    ['the note changed too', 'note of an existing expense cannot change', static function (array &$p): void { $p['note'] = 'edited'; }],
    ['the date changed too', 'expenseDate of an existing expense cannot change', static function (array &$p): void { $p['expenseDate'] = '2026-11-06'; }],
    ['the user changed too', 'userId of an existing expense cannot change', static function (array &$p): void { $p['userId'] = null; }],
    ['the created time changed', 'createdAt of an existing expense cannot change', static function (array &$p): void { $p['createdAt'] = '2026-01-01T00:00:00.000000Z'; }],
    ['the device that made it changed', 'not created by this device', static function (array &$p): void { $p['createdByDeviceId'] = 'someone-else'; }],
    ['the sync state changed', 'syncState of an existing expense cannot change', static function (array &$p): void { $p['syncState'] = 'synced'; }],
    ['an edit that is not a deletion', 'can only be deleted', static function (array &$p): void { $p['deletedAt'] = null; }],
    ['deleted at one time, updated at another', 'can only be deleted', static function (array &$p): void { $p['updatedAt'] = '2099-01-01T00:00:00.000000Z'; }],
    ['not newer than what is stored', 'can only be deleted', static function (array &$p): void { $p['updatedAt'] = '2000-01-01T00:00:00.000000Z'; $p['deletedAt'] = '2000-01-01T00:00:00.000000Z'; }],
    ['a deletion time that is not a time', 'valid time', static function (array &$p): void { $p['deletedAt'] = 'later'; }],
    ['a deletion at exactly the time already stored', 'can only be deleted', static function (array &$p) use ($scenarios): void {
        $same = $scenarios['expense_created_then_deleted']['stages'][0]['changes'][0]['payload']['updatedAt'];
        $p['updatedAt'] = $same;
        $p['deletedAt'] = $same;
    }],
    ['an extra field', 'wrong fields', static function (array &$p): void { $p['restore'] = true; }],
];
foreach ($badDeletes as [$what, $reason, $mutate]) {
    expectRefused("delete: $what", $reason, static function () use ($scenarios, $mutate): void {
        badDelete($scenarios, $mutate);
    }, $failures);
}
expectRefused('delete: an expense that is already deleted', 'already deleted', static function () use ($scenarios): void {
    badDelete($scenarios, static function (array &$p): void {}, static function (array &$stored): void {
        $stored['deletedAt'] = '2026-11-12T00:00:00.000000Z';
        $stored['updatedAt'] = '2026-11-12T00:00:00.000000Z';
    });
}, $failures);
expectRefused('bringing a deleted expense back', 'already deleted', static function () use ($scenarios): void {
    badDelete($scenarios, static function (array &$p): void { $p['deletedAt'] = null; $p['updatedAt'] = '2099-01-01T00:00:00.000000Z'; }, static function (array &$stored): void {
        $stored['deletedAt'] = '2026-11-12T00:00:00.000000Z';
        $stored['updatedAt'] = '2026-11-12T00:00:00.000000Z';
    });
}, $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    fwrite(STDERR, count($failures) . " of $checks expense guard checks FAILED\n");
    exit(1);
}
echo "Expense guard tests passed: $checks checks (the app's own records accepted, an expense deleted by the device that entered it, " . (count($bad) + count($badDeletes) + 9) . " kinds of bad records refused).\n";
