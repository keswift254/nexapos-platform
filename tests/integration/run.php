<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Platform\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Platform\Core\Database;
use Platform\Services\MaintenanceService;
use Platform\Services\PaymentReconciler;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function request(string $baseUrl, string $action, string $method = 'GET', ?array $body = null, array $headers = []): array
{
    $ch = curl_init($baseUrl . '?action=' . rawurlencode($action));
    $allHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        $allHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
}

function concurrentRequests(string $baseUrl, array $specs): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($specs as $spec) {
        $ch = curl_init($baseUrl . '?action=' . rawurlencode($spec['action']));
        $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $spec['headers'] ?? []);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($spec['body'], JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[] = $ch;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $responses = [];
    foreach ($handles as $ch) {
        $responses[] = [
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => json_decode((string) curl_multi_getcontent($ch), true),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $responses;
}

function register(string $baseUrl, string $deviceId, ?string $channel = null): array
{
    $body = [
        'device_id' => $deviceId,
        'device_label' => $deviceId,
        'registration_secret' => 'secret-' . $deviceId,
    ];
    if ($channel !== null) {
        $body['channel'] = $channel;
    }
    return request($baseUrl, 'register_device', 'POST', $body);
}

$baseUrl = getenv('TEST_PLATFORM_URL') ?: 'http://127.0.0.1:8080/index.php';
$adminSecret = getenv('PLATFORM_ADMIN_SECRET') ?: 'integration-admin';
$paystackSecret = getenv('PLATFORM_PAYSTACK_SECRET_KEY') ?: 'integration-paystack-secret';

$race = concurrentRequests($baseUrl, [
    ['action' => 'register_device', 'body' => ['device_id' => 'registration-race', 'device_label' => 'A', 'registration_secret' => 'race-secret']],
    ['action' => 'register_device', 'body' => ['device_id' => 'registration-race', 'device_label' => 'B', 'registration_secret' => 'race-secret']],
]);
$statuses = array_column($race, 'status');
sort($statuses);
check($statuses === [201, 201], 'Proof-of-possession retries should both succeed without creating duplicate clients: ' . json_encode($statuses));
$pdo = Database::connection();
check((int) $pdo->query("SELECT COUNT(*) FROM clients WHERE device_id = 'registration-race'")->fetchColumn() === 1, 'Concurrent registration created duplicate clients.');
check((int) $pdo->query('SELECT COUNT(*) FROM shops')->fetchColumn() === 1, 'Concurrent registration left an orphan shop.');
$wrongSecret = request($baseUrl, 'register_device', 'POST', [
    'device_id' => 'registration-race',
    'registration_secret' => 'wrong-secret',
]);
check($wrongSecret['status'] === 409, 'Registration recovery accepted the wrong device secret.');
$pdo->exec("UPDATE clients SET status = 'disabled' WHERE device_id = 'registration-race'");
$disabledRecovery = request($baseUrl, 'register_device', 'POST', [
    'device_id' => 'registration-race',
    'registration_secret' => 'race-secret',
]);
check($disabledRecovery['status'] === 409, 'Registration recovery reactivated a disabled device.');

$owner = register($baseUrl, 'owner-device');
$joinA = register($baseUrl, 'join-device-a');
$joinB = register($baseUrl, 'join-device-b');
check($owner['status'] === 201 && $joinA['status'] === 201 && $joinB['status'] === 201, 'Could not register join fixtures.');
$ownerKey = $owner['body']['api_key'];
$invite = request($baseUrl, 'generate_invite', 'POST', [], ['Authorization: Bearer ' . $ownerKey]);
check($invite['status'] === 200, 'Could not create invite.');
$selfJoin = request($baseUrl, 'join_shop', 'POST', ['invite_code' => $invite['body']['code']], ['Authorization: Bearer ' . $ownerKey]);
check($selfJoin['status'] === 422, 'A device must not join its own shop.');
$joinRace = concurrentRequests($baseUrl, [
    ['action' => 'join_shop', 'body' => ['invite_code' => $invite['body']['code']], 'headers' => ['Authorization: Bearer ' . $joinA['body']['api_key']]],
    ['action' => 'join_shop', 'body' => ['invite_code' => $invite['body']['code']], 'headers' => ['Authorization: Bearer ' . $joinB['body']['api_key']]],
]);
$joinStatuses = array_column($joinRace, 'status');
sort($joinStatuses);
check($joinStatuses === [200, 422], 'Exactly one concurrent invite claim must succeed.');
$joinedKey = $joinRace[0]['status'] === 200 ? $joinA['body']['api_key'] : $joinB['body']['api_key'];
$joinedDeviceId = $joinRace[0]['status'] === 200 ? 'join-device-a' : 'join-device-b';
$ownerClientId = (int) $pdo->query("SELECT id FROM clients WHERE device_id = 'owner-device'")->fetchColumn();
$joinedClientId = (int) $pdo->query("SELECT id FROM clients WHERE device_id = '" . $joinedDeviceId . "'")->fetchColumn();
$joinedStatusBefore = $pdo->query("SELECT status FROM clients WHERE id = $joinedClientId")->fetchColumn();
$devicesBefore = request($baseUrl, 'list_devices', 'GET', null, ['Authorization: Bearer ' . $ownerKey]);
check($devicesBefore['status'] === 200 && count($devicesBefore['body']['devices'] ?? []) === 2,
    'The owner must see the primary device and joined peer.');
check(request($baseUrl, 'revoke_device', 'POST', ['client_id' => $ownerClientId], ['Authorization: Bearer ' . $ownerKey])['status'] === 422,
    'The primary device revoked itself.');
// The SQL guard must protect any owner-marked target, not just the caller's id.
$pdo->prepare('UPDATE clients SET is_owner = 1 WHERE id = ?')->execute([$joinedClientId]);
$ownerTarget = request($baseUrl, 'revoke_device', 'POST', ['client_id' => $joinedClientId], ['Authorization: Bearer ' . $ownerKey]);
check($ownerTarget['status'] === 422 && $pdo->query("SELECT status FROM clients WHERE id = $joinedClientId")->fetchColumn() === $joinedStatusBefore,
    'A second owner-marked device was revoked: ' . $ownerTarget['raw']);
$pdo->prepare('UPDATE clients SET is_owner = 0 WHERE id = ?')->execute([$joinedClientId]);
check(request($baseUrl, 'revoke_device', 'POST', ['client_id' => $joinedClientId], ['Authorization: Bearer ' . $ownerKey])['status'] === 200,
    'Could not revoke a joined device.');
$devicesAfter = request($baseUrl, 'list_devices', 'GET', null, ['Authorization: Bearer ' . $ownerKey]);
check($devicesAfter['status'] === 200 && count($devicesAfter['body']['devices'] ?? []) === 1
    && (int) $devicesAfter['body']['devices'][0]['id'] === $ownerClientId,
    'A revoked device remained in the owner device list.');
check($pdo->query("SELECT status FROM clients WHERE id = $joinedClientId")->fetchColumn() === 'disabled',
    'Hiding a revoked device deleted or re-enabled its audit record.');
$pdo->prepare('UPDATE clients SET status = ? WHERE id = ?')->execute([$joinedStatusBefore, $joinedClientId]);
$peerInvite = request($baseUrl, 'generate_invite', 'POST', [], ['Authorization: Bearer ' . $joinedKey]);
check($peerInvite['status'] === 403, 'A joined device must not invite other devices.');
$membership = request($baseUrl, 'client_status', 'GET', null, ['Authorization: Bearer ' . $joinedKey]);
check($membership['status'] === 200 && (int) ($membership['body']['shop_id'] ?? 0) > 0,
    'Client status must identify the shop for interrupted-change recovery.');
$ownerLan = request($baseUrl, 'lan_sync_credentials', 'GET', null, ['Authorization: Bearer ' . $ownerKey]);
$joinedLan = request($baseUrl, 'lan_sync_credentials', 'GET', null, ['Authorization: Bearer ' . $joinedKey]);
check($ownerLan['status'] === 200 && $joinedLan['status'] === 200
    && base64_decode((string) $ownerLan['body']['secret'], true) !== false
    && strlen((string) base64_decode((string) $ownerLan['body']['secret'], true)) === 32
    && $ownerLan['body']['secret'] === $joinedLan['body']['secret'],
    'Native peers in one shop must receive the same 256-bit LAN key.');

// The shop's license, as its main device reports it and joined devices follow it.
$viewOf = static fn (string $key): array => request($baseUrl, 'client_status', 'GET', null, ['Authorization: Bearer ' . $key])['body'];
$report = static fn (string $key, array $body): array => request($baseUrl, 'report_shop_license', 'POST', $body, ['Authorization: Bearer ' . $key]);
$before = $viewOf($joinedKey);
check(array_key_exists('license', $before) && $before['license'] === null, 'A shop that never reported has no license view: ' . json_encode($before));
check(strtotime((string) ($before['server_time'] ?? '')) !== false && abs(strtotime($before['server_time']) - time()) < 10,
    "client_status must carry the server's clock so a joined device can count the license against it.");
check($report($joinedKey, ['state' => 'active'])['status'] === 403, 'A joined device reported the shop license.');
check($report($ownerKey, ['state' => 'bogus'])['status'] === 422, 'An unknown license state was accepted.');
check($report($ownerKey, ['state' => 'active', 'valid_until' => 'not a date'])['status'] === 422, 'A garbage end date was accepted.');
$licenseNow = (int) floor(microtime(true) * 1000);
$until = gmdate('Y-m-d\TH:i:s\Z', time() + 5 * 86400);
check($report($ownerKey, ['state' => 'active', 'valid_until' => $until, 'checked_at' => $licenseNow])['status'] === 200, 'The main device could not report its license.');
$seen = $viewOf($joinedKey)['license'] ?? null;
check($seen !== null && $seen['state'] === 'active' && $seen['valid_until'] === $until
    && $seen['never_expires'] === false && $seen['checked_at'] === $licenseNow,
    'A joined device did not see the main device\'s license: ' . json_encode($seen));
check(($viewOf($ownerKey)['license']['state'] ?? '') === 'active', 'The main device did not see its own reported license.');
check($report($ownerKey, ['state' => 'active', 'valid_until' => null, 'checked_at' => $licenseNow + 10])['status'] === 200, 'A never-expiring license could not be reported.');
$seen = $viewOf($joinedKey)['license'];
check($seen['never_expires'] === true && $seen['valid_until'] === null, 'A license with no end date must read as never expiring: ' . json_encode($seen));
// An older report (a delayed one) must not undo a newer one.
check($report($ownerKey, ['state' => 'expired', 'checked_at' => $licenseNow - 5000])['status'] === 200, 'A stale report should be accepted and ignored, not refused.');
check($viewOf($joinedKey)['license']['state'] === 'active', 'A stale report overwrote a newer one.');
// The newest report wins, in either direction.
check($report($ownerKey, ['state' => 'expired', 'valid_until' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600), 'checked_at' => $licenseNow + 20])['status'] === 200, 'Could not report an expired license.');
$seen = $viewOf($joinedKey)['license'];
check($seen['state'] === 'expired' && $seen['never_expires'] === false && $seen['checked_at'] === $licenseNow + 20, 'The newer report did not win: ' . json_encode($seen));
// A report dated in the future (a wrong clock) is counted as "now", so it cannot outrank honest ones.
// The preceding fixture stamped its report 20 ms ahead. Let the test server's
// clock reach that stamp before testing a future report, or a fast CI runner
// correctly ignores the clamped report as stale.
while ((int) floor(microtime(true) * 1000) <= $licenseNow + 20) {
    usleep(1000);
}
$farAhead = $licenseNow + 3 * 86400 * 1000;
check($report($ownerKey, ['state' => 'active', 'valid_until' => $until, 'checked_at' => $farAhead])['status'] === 200, 'A future-dated report was refused.');
$seen = $viewOf($joinedKey)['license'];
check($seen['checked_at'] < $licenseNow + 3600 * 1000, 'A report from the future kept its future stamp: ' . json_encode($seen));
$browserReporter = register($baseUrl, 'license-browser-owner', 'browser');
check($report($browserReporter['body']['api_key'], ['state' => 'active'])['status'] === 403, 'A browser client reported a shop license.');
// A license REVOKED on the license server marks the shop revoked; un-revoking clears the mark.
$revokeOwner = register($baseUrl, 'license-revoke-owner', 'native');
$revokeKey = $revokeOwner['body']['api_key'];
check($report($revokeKey, ['state' => 'active', 'valid_until' => $until])['status'] === 200, 'Could not report for the revoke fixture.');
$asAdmin = static fn (string $action, array $body): array => request($baseUrl, $action, 'POST', $body, ['X-Admin-Secret: ' . $adminSecret]);
check($asAdmin('admin_revoke_device_by_device_id', ['device_id' => 'license-revoke-owner'])['status'] === 200, 'Could not revoke the fixture device.');
$revokedShop = (int) $pdo->query("SELECT shop_id FROM clients WHERE device_id = 'license-revoke-owner'")->fetchColumn();
$row = $pdo->query("SELECT license_state, license_valid_until FROM shops WHERE id = $revokedShop")->fetch();
check($row['license_state'] === 'revoked' && $row['license_valid_until'] === null, 'A license revoke did not mark the shop revoked.');
check($asAdmin('admin_restore_device_by_device_id', ['device_id' => 'license-revoke-owner'])['status'] === 200, 'Could not restore the fixture device.');
check($pdo->query("SELECT license_state FROM shops WHERE id = $revokedShop")->fetchColumn() === null, 'Un-revoking left the shop marked revoked.');
$otherShopState = $pdo->query("SELECT license_state FROM shops WHERE id = " . (int) $pdo->query("SELECT shop_id FROM clients WHERE device_id = 'owner-device'")->fetchColumn())->fetchColumn();
check($otherShopState === 'active', 'Another shop\'s license view was disturbed by the revoke: ' . var_export($otherShopState, true));

$relayStamp = gmdate('Y-m-d\TH:i:s') . '.654321Z';
$relayChange = [
    'source_device_id' => $joinedDeviceId,
    'table_name' => 'categories',
    'row_id' => 'lan-relay-row',
    'local_rev' => 77,
    'updated_at' => $relayStamp,
    'payload' => [
        'id' => 'lan-relay-row',
        'localRev' => 77,
        'updatedAt' => $relayStamp,
        'createdByDeviceId' => $joinedDeviceId,
    ],
];
$relayPush = request($baseUrl, 'push_changes', 'POST', ['changes' => [$relayChange]], ['Authorization: Bearer ' . $ownerKey]);
check($relayPush['status'] === 200, 'An active same-shop native peer could not relay a LAN change: ' . $relayPush['raw']);
$relayRetry = request($baseUrl, 'push_changes', 'POST', ['changes' => [$relayChange]], ['Authorization: Bearer ' . $ownerKey]);
check($relayRetry['status'] === 200
    && (int) $pdo->query("SELECT COUNT(*) FROM sync_changes WHERE device_id = '$joinedDeviceId' AND local_rev = 77")->fetchColumn() === 1,
    'Relayed source/local revision was not deduplicated.');
$conflictingRelay = $relayChange;
$conflictingRelay['row_id'] = 'lan-relay-conflict';
$conflictingRelay['payload']['id'] = 'lan-relay-conflict';
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [$conflictingRelay]], ['Authorization: Bearer ' . $ownerKey])['status'] === 422,
    'A conflicting replay of the same source revision was accepted.');
// A product revision re-sent after a lost acknowledgement may carry a different
// stockQty / imagePath: the device recomputes stock without bumping local_rev,
// and no receiver applies either field. That must be accepted as already
// recorded - refusing it stalled a shop's whole upload queue in production -
// while a real difference (price) or another table's field must still be refused.
$productStamp = gmdate('Y-m-d\TH:i:s') . '.111111Z';
$productChange = static fn (array $extra = [], int $rev = 91): array => [
    'table_name' => 'products', 'row_id' => 'stock-resend-product', 'local_rev' => $rev, 'updated_at' => $productStamp,
    'payload' => array_merge([
        'id' => 'stock-resend-product', 'localRev' => $rev, 'updatedAt' => $productStamp,
        'createdByDeviceId' => 'owner-device', 'name' => 'Sugar', 'priceMinor' => 15000,
        'stockQty' => 10, 'imagePath' => null,
    ], $extra),
];
$ownerAuth = ['Authorization: Bearer ' . $ownerKey];
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [$productChange()]], $ownerAuth)['status'] === 200,
    'Could not record the product fixture.');
$stockOnly = request($baseUrl, 'push_changes', 'POST', ['changes' => [$productChange(['stockQty' => 7, 'imagePath' => 'C:\\pics\\a.png'])]], $ownerAuth);
check($stockOnly['status'] === 200, 'A re-sent product revision differing only in stockQty/imagePath was refused: ' . $stockOnly['raw']);
check((int) $pdo->query("SELECT COUNT(*) FROM sync_changes WHERE device_id = 'owner-device' AND local_rev = 91")->fetchColumn() === 1,
    'The stock-only resend created a second copy of the revision.');
check(json_decode((string) $pdo->query("SELECT payload FROM sync_changes WHERE device_id = 'owner-device' AND local_rev = 91")->fetchColumn(), true)['stockQty'] === 10,
    'The stock-only resend overwrote the stored revision.');
$priceChange = request($baseUrl, 'push_changes', 'POST', ['changes' => [$productChange(['priceMinor' => 99999])]], $ownerAuth);
check($priceChange['status'] === 422 && str_contains((string) ($priceChange['body']['message'] ?? ''), 'different content'),
    'A re-sent product revision with a different price was accepted: ' . $priceChange['raw']);
$extraField = request($baseUrl, 'push_changes', 'POST', ['changes' => [$productChange(['barcode' => 'X1'])]], $ownerAuth);
check($extraField['status'] === 422, 'A re-sent product revision with an extra field was accepted.');
$otherTableStock = $productChange(['stockQty' => 3], 92);
$otherTableStock['table_name'] = 'categories';
$otherTableStock['row_id'] = $otherTableStock['payload']['id'] = 'stock-resend-category';
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [$otherTableStock]], $ownerAuth)['status'] === 200, 'Could not record the category fixture.');
$otherTableStock['payload']['stockQty'] = 4;
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [$otherTableStock]], $ownerAuth)['status'] === 422,
    'The device-local allowance leaked to a table other than products.');

$inactiveRelay = $relayChange;
$inactiveRelay['local_rev'] = 78;
$inactiveRelay['payload']['localRev'] = 78;
$pdo->prepare("UPDATE clients SET status = 'disabled' WHERE device_id = ?")->execute([$joinedDeviceId]);
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [$inactiveRelay]], ['Authorization: Bearer ' . $ownerKey])['status'] === 422,
    'A relay attributed to a disabled source device was accepted.');
$pdo->prepare("UPDATE clients SET status = 'active' WHERE device_id = ?")->execute([$joinedDeviceId]);

$otherNative = register($baseUrl, 'other-shop-native', 'native');
$crossShopRelay = $relayChange;
$crossShopRelay['source_device_id'] = 'other-shop-native';
$crossShopRelay['local_rev'] = 1;
$crossShopRelay['payload']['createdByDeviceId'] = 'other-shop-native';
$crossShopRelay['payload']['localRev'] = 1;
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [$crossShopRelay]], ['Authorization: Bearer ' . $ownerKey])['status'] === 422,
    'A relay attributed to a device from another shop was accepted.');
$joinedLeave = request($baseUrl, 'leave_shop', 'POST', [], ['Authorization: Bearer ' . $joinedKey]);
check($joinedLeave['status'] === 200, 'A non-owner device must be able to leave a shared shop safely.');
$leftMembership = request($baseUrl, 'client_status', 'GET', null, ['Authorization: Bearer ' . $joinedKey]);
check($leftMembership['status'] === 200 && ($leftMembership['body']['is_owner'] ?? false) === true,
    'A departed device must own its new empty shop.');

$oversized = array_fill(0, 1001, []);
$tooLarge = request($baseUrl, 'push_changes', 'POST', ['changes' => $oversized], ['Authorization: Bearer ' . $ownerKey]);
check($tooLarge['status'] === 413, 'Legacy sync hard ceiling was not enforced.');

// A wrong-year device clock (or a forged stamp) must not be able to poison
// last-write-wins for the whole shop.
$nextRevision = 0;
$change = static function (string $rowId, string $stamp) use (&$nextRevision): array {
    $revision = ++$nextRevision;
    return [
        'table_name' => 'categories', 'row_id' => $rowId, 'local_rev' => $revision, 'updated_at' => $stamp,
        'payload' => [
            'id' => $rowId,
            'localRev' => $revision,
            'updatedAt' => $stamp,
            'createdByDeviceId' => 'owner-device',
        ],
    ];
};
$farFuture = gmdate('Y-m-d\TH:i:s', time() + 5 * 365 * 86400) . '.000000Z';
$futurePush = request($baseUrl, 'push_changes', 'POST', ['changes' => [$change('clock-future', $farFuture)]], ['Authorization: Bearer ' . $ownerKey]);
check($futurePush['status'] === 422 && str_contains((string) ($futurePush['body']['message'] ?? ''), 'future'),
    'A change dated years ahead was accepted: ' . $futurePush['raw']);
$garbagePush = request($baseUrl, 'push_changes', 'POST', ['changes' => [$change('clock-garbage', 'zzzz')]], ['Authorization: Bearer ' . $ownerKey]);
check($garbagePush['status'] === 422, 'A non-timestamp updated_at was accepted (it sorts after every real date).');
$nowPush = request($baseUrl, 'push_changes', 'POST', ['changes' => [$change('clock-ok', gmdate('Y-m-d\TH:i:s') . '.123456Z')]], ['Authorization: Bearer ' . $ownerKey]);
check($nowPush['status'] === 200, 'A correctly-stamped change was rejected: ' . $nowPush['raw']);
$slightlyAhead = gmdate('Y-m-d\TH:i:s', time() + 3 * 3600) . '.000000Z';
$driftPush = request($baseUrl, 'push_changes', 'POST', ['changes' => [$change('clock-drift', $slightlyAhead)]], ['Authorization: Bearer ' . $ownerKey]);
check($driftPush['status'] === 200, 'Ordinary clock drift (3 hours ahead) must still sync: ' . $driftPush['raw']);

// Planned maintenance (moving the database): while config/maintenance.flag exists every request
// - writes included - answers 503 before the database is touched, so nothing can be recorded
// into a database that is being copied; removing the file restores service immediately.
$maintenanceFlag = __DIR__ . '/../../config/maintenance.flag';
try {
    file_put_contents($maintenanceFlag, 'test');
    $heldPush = request($baseUrl, 'push_changes', 'POST', ['changes' => [$change('maintenance-row', gmdate('Y-m-d\TH:i:s') . '.222222Z')]], ['Authorization: Bearer ' . $ownerKey]);
    check($heldPush['status'] === 503, 'A write during maintenance was not refused with 503: ' . $heldPush['raw']);
    check(request($baseUrl, 'health')['status'] === 503, 'The health check answered during maintenance.');
    check((int) $pdo->query("SELECT COUNT(*) FROM sync_changes WHERE row_id = 'maintenance-row'")->fetchColumn() === 0,
        'A change was recorded while the service was in maintenance.');
} finally {
    @unlink($maintenanceFlag);
}
$resumedPush = request($baseUrl, 'push_changes', 'POST', ['changes' => [$change('maintenance-row', gmdate('Y-m-d\TH:i:s') . '.222222Z')]], ['Authorization: Bearer ' . $ownerKey]);
check($resumedPush['status'] === 200, 'Service did not resume after maintenance ended: ' . $resumedPush['raw']);

$ownerId = (int) $pdo->query("SELECT id FROM clients WHERE device_id = 'owner-device'")->fetchColumn();
$insertTransaction = $pdo->prepare("INSERT INTO transactions
    (client_id, reference, amount_minor, currency, subaccount_code)
    VALUES (?, 'webhook-reference', 1000, 'KES', 'SUB_TEST')");
$insertTransaction->execute([$ownerId]);
$event = ['event' => 'charge.success', 'data' => ['id' => 9000001, 'reference' => 'webhook-reference']];
$rawEvent = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha512', $rawEvent, $paystackSecret);
$webhook = request($baseUrl, 'paystack_webhook', 'POST', $event, ['X-Paystack-Signature: ' . $signature]);
check($webhook['status'] === 200 && $webhook['body']['outcome'] === 'success', 'Signed webhook was not reconciled.');
$duplicate = request($baseUrl, 'paystack_webhook', 'POST', $event, ['X-Paystack-Signature: ' . $signature]);
check($duplicate['status'] === 200 && $duplicate['body']['outcome'] === 'duplicate', 'Webhook idempotency failed.');
check($pdo->query("SELECT status FROM transactions WHERE reference = 'webhook-reference'")->fetchColumn() === 'verified_success', 'Webhook did not update the transaction.');
check(request($baseUrl, 'paystack_webhook', 'POST', $event, ['X-Paystack-Signature: bad'])['status'] === 401, 'Invalid webhook signature was accepted.');

$pdo->prepare("INSERT INTO sync_changes (shop_id, table_name, row_id, device_id, local_rev, updated_at, payload, received_at)
    VALUES ((SELECT shop_id FROM clients WHERE id = ?), 'categories', 'compact-row', 'owner-device', ?, ?, ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY))")
    ->execute([$ownerId, 1001, '2026-01-01T00:00:00Z', '{"id":"compact-row"}']);
$pdo->prepare("INSERT INTO sync_changes (shop_id, table_name, row_id, device_id, local_rev, updated_at, payload, received_at)
    VALUES ((SELECT shop_id FROM clients WHERE id = ?), 'categories', 'compact-row', 'owner-device', ?, ?, ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY))")
    ->execute([$ownerId, 1002, '2026-01-02T00:00:00Z', '{"id":"compact-row"}']);
$pdo->prepare("INSERT INTO join_attempts (client_id, ip_address, attempted_at) VALUES (?, '127.0.0.2', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY))")
    ->execute([$ownerId]);
$maintenance = new MaintenanceService($pdo, [
    'sync_history_retention_days' => 7,
    'join_attempt_retention_days' => 7,
    'invite_retention_days' => 7,
    'maintenance_batch_size' => 100,
    'maintenance_max_batches' => 5,
]);
$deleted = $maintenance->run();
check($deleted['sync_changes'] === 1, 'Sync compaction did not delete exactly one superseded revision.');
check((int) $pdo->query("SELECT COUNT(*) FROM sync_changes WHERE row_id = 'compact-row'")->fetchColumn() === 1, 'Sync compaction removed the latest row.');
check($deleted['join_attempts'] >= 1, 'Old join attempts were not pruned.');

// Browser-POS channel gate: a browser-registered device must never
// reach settlement/payout data, even as its own shop's owner (a
// brand-new device with no invite code always owns the shop it
// registers) - see clients.channel's own schema comment for why
// is_owner alone isn't the boundary here.
$browserOwner = register($baseUrl, 'browser-owner-device', 'browser');
check($browserOwner['status'] === 201, 'Could not register the browser-channel fixture.');
$browserKey = $browserOwner['body']['api_key'];
$browserLan = request($baseUrl, 'lan_sync_credentials', 'GET', null, ['Authorization: Bearer ' . $browserKey]);
check($browserLan['status'] === 403 && !array_key_exists('secret', $browserLan['body']),
    'A browser client was given LAN sync credentials.');
$browserStatus = request($baseUrl, 'client_status', 'GET', null, ['Authorization: Bearer ' . $browserKey]);
check($browserStatus['status'] === 200 && ($browserStatus['body']['is_owner'] ?? false) === true,
    'A freshly registered device must own its own new shop, regardless of channel.');
check(
    // Deliberately not `?? 'sentinel'`: that operator treats an EXISTING
    // key whose value is a real null the same as a missing key, so it
    // can't tell "redacted to null" apart from "field absent entirely" -
    // exactly the distinction this assertion needs to make.
    array_key_exists('business_name', $browserStatus['body']) && $browserStatus['body']['business_name'] === null
        && $browserStatus['body']['bank_code'] === null
        && $browserStatus['body']['account_number'] === null
        && $browserStatus['body']['account_name'] === null
        && $browserStatus['body']['subaccount_code'] === null
        && $browserStatus['body']['is_verified'] === false,
    'client_status must redact every settlement field for a browser-channel client, even its own owner: ' . $browserStatus['raw']
);
$browserBanks = request($baseUrl, 'list_banks', 'GET', null, ['Authorization: Bearer ' . $browserKey]);
check($browserBanks['status'] === 403, 'list_banks must refuse a browser-channel client.');
$browserSettle = request($baseUrl, 'save_settlement_details', 'POST', [
    'business_name' => 'Should Not Save',
    'settlement_type' => 'mpesa',
    'bank_code' => 'MPESA',
    'account_number' => '0700000000',
], ['Authorization: Bearer ' . $browserKey]);
check($browserSettle['status'] === 403, 'save_settlement_details must refuse a browser-channel client even as owner.');
$nativeOwner = register($baseUrl, 'native-owner-device', 'native');
check($nativeOwner['status'] === 201 && $nativeOwner['body']['api_key'] !== $browserKey, 'Could not register the native-channel control fixture.');
// Set fake settlement data directly (not through save_settlement_details,
// which would need a real outbound Paystack call this suite has no
// sandbox key for) purely to give client_status something real to
// either redact or not - the point of this control case is proving
// native ISN'T also accidentally redacted, not exercising Paystack.
$pdo->prepare("UPDATE shops SET business_name = 'Control Shop', subaccount_code = 'SUB_CONTROL', is_verified = 1
    WHERE id = (SELECT shop_id FROM clients WHERE device_id = 'native-owner-device')")->execute();
$nativeStatus = request($baseUrl, 'client_status', 'GET', null, ['Authorization: Bearer ' . $nativeOwner['body']['api_key']]);
check($nativeStatus['status'] === 200 && ($nativeStatus['body']['business_name'] ?? null) === 'Control Shop',
    'client_status must NOT redact settlement fields for a native-channel client (control case).');

// Where the customer's browser goes after paying. initialize_transaction relays the request to
// Paystack and the fake echoes the payload it received, so callback_url is exactly what
// Paystack would have been told. A client only picks between fixed destinations.
$initializeAs = static fn (array $extra): array => request($baseUrl, 'initialize_transaction', 'POST', array_merge([
    'email' => 'buyer@example.com', 'amount' => 5000, 'currency' => 'KES', 'reference' => 'RETURN-' . bin2hex(random_bytes(4)),
], $extra), ['Authorization: Bearer ' . $nativeOwner['body']['api_key']]);
$plainInit = $initializeAs([]);
check($plainInit['status'] === 200 && !isset($plainInit['body']['data']['callback_url']),
    'A client that asked for no return was given a callback: ' . $plainInit['raw']);
$nativeInit = $initializeAs(['return_to_app' => true]);
check(($nativeInit['body']['data']['callback_url'] ?? '') === 'https://keswift254.github.io/nexapos-site/checkout-return.html',
    'The native app return changed: ' . $nativeInit['raw']);
$webInit = $initializeAs(['return_to' => 'web']);
check(($webInit['body']['data']['callback_url'] ?? '') === 'https://nexapos.cc/payment-return.html',
    'The web POS was not sent back to its own return page: ' . $webInit['raw']);
check(($webInit['body']['data']['transaction_charge'] ?? -1) === 0 && ($webInit['body']['data']['subaccount'] ?? '') === 'SUB_CONTROL',
    'The web return changed the settlement split: ' . $webInit['raw']);
// The email Paystack is given is never the one the app sent (the shop's shared default): Paystack
// blocks a business that uses one address for every payment.
check(($plainInit['body']['data']['email'] ?? '') !== 'buyer@example.com'
    && str_ends_with((string) ($plainInit['body']['data']['email'] ?? ''), '@nexapos.cc'),
    'The shop\'s shared email still reached Paystack: ' . $plainInit['raw']);
check(($plainInit['body']['data']['email'] ?? '') !== ($nativeInit['body']['data']['email'] ?? ''),
    'Two payments were given the same email.');
// Short and readable: s<shop>-<running number>, and the number counts up with each payment.
$parseNumber = static fn (array $response): ?int => preg_match('/^s\d+-(\d{4,})@nexapos\.cc$/', (string) ($response['body']['data']['email'] ?? ''), $m) === 1 ? (int) $m[1] : null;
check($parseNumber($plainInit) !== null && $parseNumber($nativeInit) === $parseNumber($plainInit) + 1 && $parseNumber($webInit) === $parseNumber($nativeInit) + 1,
    'The per-payment email is not s<shop>-<running number>, counting up: ' . json_encode([$plainInit['body']['data']['email'] ?? null, $nativeInit['body']['data']['email'] ?? null, $webInit['body']['data']['email'] ?? null]));
$phoneInit = $initializeAs(['customer_phone' => '0712 345 678']);
$phoneInitAgain = $initializeAs(['customer_phone' => '+254712345678']);
check(($phoneInit['body']['data']['email'] ?? '') === '254712345678@nexapos.cc'
    && ($phoneInitAgain['body']['data']['email'] ?? '') === ($phoneInit['body']['data']['email'] ?? ''),
    'The same customer phone must always give the same address: ' . $phoneInit['raw']);
$spoofInit = $initializeAs(['return_to' => 'https://evil.example/steal', 'callback_url' => 'https://evil.example/steal']);
check($spoofInit['status'] === 200 && !isset($spoofInit['body']['data']['callback_url']),
    'A client-supplied return address reached Paystack: ' . $spoofInit['raw']);

// Support tickets: a real round trip, not just "the route exists" (the
// separate verify-production-1.0.57.yml workflow only checks for a 401/403
// on an unauthenticated support_list - it never opened a ticket for real).
$supportOwner = register($baseUrl, 'support-owner-device');
check($supportOwner['status'] === 201, 'Could not register the support fixture device.');
$supportKey = $supportOwner['body']['api_key'];
$pdo->prepare("UPDATE shops SET business_name = 'Support Fixture Shop' WHERE id = (SELECT shop_id FROM clients WHERE device_id = 'support-owner-device')")->execute();
$emptyList = request($baseUrl, 'support_list', 'GET', null, ['Authorization: Bearer ' . $supportKey]);
check($emptyList['status'] === 200 && $emptyList['body']['tickets'] === [], 'A shop with no tickets must list none.');
check(request($baseUrl, 'support_open', 'POST', ['subject' => 'Hi', 'message' => 'short subject'], ['Authorization: Bearer ' . $supportKey])['status'] === 422,
    'A subject under 3 characters was accepted.');
check(request($baseUrl, 'support_open', 'POST', ['subject' => str_repeat('x', 161), 'message' => 'm'], ['Authorization: Bearer ' . $supportKey])['status'] === 422,
    'A subject over 160 characters was accepted.');
check(request($baseUrl, 'support_open', 'POST', ['subject' => 'Payment question', 'message' => ''], ['Authorization: Bearer ' . $supportKey])['status'] === 422,
    'An empty message was accepted.');
check(request($baseUrl, 'support_open', 'POST', ['subject' => 'Payment question', 'message' => 'Help', 'email' => "bad\nrecipient@example.com"], ['Authorization: Bearer ' . $supportKey])['status'] === 422,
    'A malformed contact email was accepted.');
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1XkAAAAASUVORK5CYII=';
$opened = request($baseUrl, 'support_open', 'POST', ['subject' => 'Payment question', 'message' => 'My till will not sync.', 'email' => 'owner@example.com', 'attachments' => [['data' => $png]]], ['Authorization: Bearer ' . $supportKey]);
check($opened['status'] === 201 && ($opened['body']['ticket_id'] ?? 0) > 0, 'Could not open a support ticket: ' . $opened['raw']);
$ticketId = $opened['body']['ticket_id'];
$list = request($baseUrl, 'support_list', 'GET', null, ['Authorization: Bearer ' . $supportKey]);
check($list['status'] === 200 && count($list['body']['tickets']) === 1 && $list['body']['tickets'][0]['id'] === $ticketId
    && $list['body']['tickets'][0]['status'] === 'open'
    && $list['body']['tickets'][0]['customer_email'] === 'owner@example.com',
    'The opened ticket did not retain its contact email: ' . $list['raw']);
$supportThread = static function (string $key, int $ticketId) use ($baseUrl): array {
    $ch = curl_init($baseUrl . '?action=support_thread&ticket_id=' . $ticketId);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
};
$thread = $supportThread($supportKey, $ticketId);
check($thread['status'] === 200 && $thread['body']['ticket']['id'] === $ticketId
    && count($thread['body']['messages']) === 1 && $thread['body']['messages'][0]['sender'] === 'customer'
    && $thread['body']['messages'][0]['body'] === 'My till will not sync.', 'The ticket thread was not readable after opening: ' . $thread['raw']);
check(count($thread['body']['messages'][0]['attachments'] ?? []) === 1,
    'Customer thread lost the photo attached to a new ticket.');
check(request($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => ''], ['Authorization: Bearer ' . $supportKey])['status'] === 422,
    'An empty reply was accepted.');
$reply = request($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Still broken after a reinstall.'], ['Authorization: Bearer ' . $supportKey]);
check($reply['status'] === 200, 'Could not reply to a support ticket: ' . $reply['raw']);
$threadAfterReply = $supportThread($supportKey, $ticketId);
check(count($threadAfterReply['body']['messages']) === 2 && $threadAfterReply['body']['messages'][1]['body'] === 'Still broken after a reinstall.',
    'The reply did not land in the thread.');
$otherShop = register($baseUrl, 'support-other-shop-device');
check($supportThread($otherShop['body']['api_key'], $ticketId)['status'] === 404,
    'A device from a different shop could read another shop\'s support ticket.');
check(request($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Should not land'], ['Authorization: Bearer ' . $otherShop['body']['api_key']])['status'] === 404,
    'A device from a different shop could reply to another shop\'s support ticket.');
check(request($baseUrl, 'support_close', 'POST', ['ticket_id' => $ticketId], ['Authorization: Bearer ' . $otherShop['body']['api_key']])['status'] === 404,
    'A device from a different shop could close another shop\'s support ticket.');
check(request($baseUrl, 'support_close', 'POST', ['ticket_id' => $ticketId], [])['status'] === 401,
    'support_close was reachable with no API key.');
check(request($baseUrl, 'support_list', 'GET', null, [])['status'] === 401, 'support_list was reachable with no API key.');
check(request($baseUrl, 'support_open', 'POST', ['subject' => 'x', 'message' => 'y'], [])['status'] === 401, 'support_open was reachable with no API key.');

// The vendor's side of support: see and answer every shop's tickets,
// not just one shop's own (support_list/support_thread/support_reply
// above are shop-scoped and never reach these).
$adminSupportThread = static function (int $ticketId) use ($baseUrl, $adminSecret): array {
    $ch = curl_init($baseUrl . '?action=admin_support_thread&ticket_id=' . $ticketId);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Admin-Secret: ' . $adminSecret],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
};

check(request($baseUrl, 'admin_list_support_tickets', 'GET', null, [])['status'] === 401,
    'admin_list_support_tickets was reachable with no admin secret.');
$noSecretThread = curl_init($baseUrl . '?action=admin_support_thread&ticket_id=' . $ticketId);
curl_setopt_array($noSecretThread, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
curl_exec($noSecretThread);
check((int) curl_getinfo($noSecretThread, CURLINFO_HTTP_CODE) === 401, 'admin_support_thread was reachable with no admin secret.');
curl_close($noSecretThread);
check(request($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'x'], [])['status'] === 401,
    'admin_support_reply was reachable with no admin secret.');

$adminList = request($baseUrl, 'admin_list_support_tickets', 'GET', null, ['X-Admin-Secret: ' . $adminSecret]);
check($adminList['status'] === 200, 'Could not list support tickets as admin: ' . $adminList['raw']);
$listed = null;
foreach ($adminList['body']['tickets'] as $row) {
    if ((int) $row['id'] === $ticketId) { $listed = $row; break; }
}
check($listed !== null, 'The earlier ticket did not appear in the admin list.');
check($listed['business_name'] === 'Support Fixture Shop' && $listed['status'] === 'open' && (int) $listed['message_count'] === 2
    && (int) $listed['attachment_count'] === 1,
    'Admin list row is wrong: ' . json_encode($listed));

$adminThread = $adminSupportThread($ticketId);
check($adminThread['status'] === 200 && (int) $adminThread['body']['ticket']['id'] === $ticketId
    && count($adminThread['body']['messages']) === 2, 'Admin could not read the full thread: ' . $adminThread['raw']);
check(count($adminThread['body']['messages'][0]['attachments'] ?? []) === 1
    && $adminThread['body']['messages'][0]['attachments'][0]['id'] === $thread['body']['messages'][0]['attachments'][0]['id'],
    'Admin thread lost the photo attached to a new ticket.');

check($asAdmin('admin_support_reply', ['ticket_id' => $ticketId, 'message' => ''])['status'] === 422,
    'An empty admin reply was accepted.');
$adminReply = $asAdmin('admin_support_reply', ['ticket_id' => $ticketId, 'message' => 'Please try reinstalling the app.']);
check($adminReply['status'] === 200, 'Could not reply as admin: ' . $adminReply['raw']);
$afterAdminReply = $adminSupportThread($ticketId);
check(count($afterAdminReply['body']['messages']) === 3
    && $afterAdminReply['body']['messages'][2]['sender'] === 'support'
    && $afterAdminReply['body']['messages'][2]['body'] === 'Please try reinstalling the app.',
    'The admin reply did not land in the thread.');
check($afterAdminReply['body']['ticket']['status'] === 'pending',
    'An admin reply must mark the ticket pending (waiting on the customer), not leave it open.');
check($afterAdminReply['body']['ticket']['customer_email'] === 'owner@example.com',
    'Admin thread lost the customer contact email.');

$shopClosed = request($baseUrl, 'support_close', 'POST', ['ticket_id' => $ticketId], ['Authorization: Bearer ' . $supportKey]);
check($shopClosed['status'] === 200 && $supportThread($supportKey, $ticketId)['body']['ticket']['status'] === 'closed',
    'The shop could not close its ticket.');

// Replying to a closed ticket reopens it and puts it back on the vendor's plate.
$customerReplyAgain = request($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Still not working.'], ['Authorization: Bearer ' . $supportKey]);
check($customerReplyAgain['status'] === 200, 'Could not reply as the customer again: ' . $customerReplyAgain['raw']);
check($adminSupportThread($ticketId)['body']['ticket']['status'] === 'open',
    'A customer reply must put the ticket back to open (waiting on the vendor).');

check($asAdmin('admin_close_support_ticket', ['ticket_id' => 999999999])['status'] === 404,
    'Closing a non-existent ticket did not 404.');
$closed = $asAdmin('admin_close_support_ticket', ['ticket_id' => $ticketId]);
check($closed['status'] === 200, 'Could not close the ticket: ' . $closed['raw']);
check($adminSupportThread($ticketId)['body']['ticket']['status'] === 'closed', 'The ticket was not actually closed.');

check($adminSupportThread(999999999)['status'] === 404, 'admin_support_thread did not 404 for a non-existent ticket.');

// ---- The old-iPad "lite" till: a joined device whose every push is vetted by the sync guard ---------------
// The records come from the real app's own tests (tests/fixtures/sync_guard), re-stamped as this lite device.
$guardFixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/sync_guard/sale_scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
$liteOwner = register($baseUrl, 'lite-shop-native-owner', 'native');
$liteTill = register($baseUrl, 'lite-till-device', 'lite');
check($liteOwner['status'] === 201 && $liteTill['status'] === 201, 'Could not register the lite till fixtures: ' . $liteOwner['raw'] . $liteTill['raw']);
$liteOwnerAuth = ['Authorization: Bearer ' . $liteOwner['body']['api_key']];
$liteAuth = ['Authorization: Bearer ' . $liteTill['body']['api_key']];
$liteInvite = request($baseUrl, 'generate_invite', 'POST', [], $liteOwnerAuth);
check($liteInvite['status'] === 200, 'Could not invite the lite till.');
check(request($baseUrl, 'join_shop', 'POST', ['invite_code' => $liteInvite['body']['code']], $liteAuth)['status'] === 200, 'The lite till could not join the shop.');
check($pdo->query("SELECT channel FROM clients WHERE device_id = 'lite-till-device'")->fetchColumn() === 'lite', 'The till was not stamped as a lite device.');

// Like a real shop: the main (native) device holds the products and the cashier.
$nativeRevision = 0;
$contextChanges = [];
foreach ($guardFixture['scenarios'] as $scenario) {
    foreach (['users', 'products'] as $table) {
        foreach ($scenario['context'][$table] as $row) {
            $row['createdByDeviceId'] = 'lite-shop-native-owner';
            $row['localRev'] = ++$nativeRevision;
            $contextChanges[] = ['table_name' => $table, 'row_id' => $row['id'], 'local_rev' => $row['localRev'], 'updated_at' => $row['updatedAt'], 'payload' => $row];
        }
    }
}
$shopSetup = request($baseUrl, 'push_changes', 'POST', ['changes' => $contextChanges], $liteOwnerAuth);
check($shopSetup['status'] === 200, 'Could not give the shop its products and cashiers: ' . $shopSetup['raw']);

$liteRevision = 0;
$asLite = static function (array $changes) use (&$liteRevision): array {
    $out = [];
    foreach ($changes as $change) {
        $payload = $change['payload'];
        $payload['createdByDeviceId'] = 'lite-till-device';
        $payload['localRev'] = ++$liteRevision;
        $out[] = ['table_name' => $change['table_name'], 'row_id' => $payload['id'], 'local_rev' => $liteRevision, 'updated_at' => $payload['updatedAt'], 'payload' => $payload];
    }
    return $out;
};
$litePush = static fn (array $changes): array => request($baseUrl, 'push_changes', 'POST', ['changes' => $changes], $liteAuth);
$liteStored = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM sync_changes WHERE device_id = 'lite-till-device'")->fetchColumn();

$cashScenario = null;
foreach ($guardFixture['scenarios'] as $scenario) {
    if ($scenario['name'] === 'cash_sale_two_lines') {
        $cashScenario = $scenario;
    }
}
$cashChanges = $cashScenario['stages'][0]['changes'];

// A tampered sale (the total does not add up) is refused, and NOTHING of it is stored.
$tampered = $cashChanges;
$tampered[0]['payload']['totalCents'] += 1;
$before = $liteStored();
$refused = $litePush($asLite($tampered));
check($refused['status'] === 422 && str_contains((string) ($refused['body']['message'] ?? ''), 'do not add up'), 'A tampered sale was not refused: ' . $refused['raw']);
check($liteStored() === $before, 'Part of a refused sale was stored.');
// A till may not write products (or anything but sales).
$productWrite = $litePush($asLite([['table_name' => 'products', 'payload' => $guardFixture['scenarios'][0]['context']['products'][0]]]));
check($productWrite['status'] === 422 && str_contains((string) ($productWrite['body']['message'] ?? ''), "may not write to 'products'"), 'A lite till wrote a product: ' . $productWrite['raw']);
check($liteStored() === $before, 'A refused product write was stored.');

// The real sale is accepted; sending the very same push again (a lost reply) is harmless.
$goodBatch = $asLite($cashChanges);
check($litePush($goodBatch)['status'] === 200, 'A real cash sale was refused: ' . $litePush($goodBatch)['raw']);
check($liteStored() === $before + count($goodBatch), 'The cash sale was not stored in full.');
check($litePush($goodBatch)['status'] === 200 && $liteStored() === $before + count($goodBatch), 'Re-sending a recorded sale was not harmless.');
// The same revision with different content is still refused (the normal duplicate rule).
$altered = $goodBatch;
$altered[0]['payload']['customerName'] = 'Someone else';
check($litePush($altered)['status'] === 422, 'A recorded revision was accepted with different content.');

// Every kind of sale the app can make is accepted, including an online payment moving on to paid / cancelled.
foreach ($guardFixture['scenarios'] as $scenario) {
    if ($scenario['name'] === 'cash_sale_two_lines') {
        continue;
    }
    foreach ($scenario['stages'] as $stage) {
        $push = $litePush($asLite($stage['changes']));
        check($push['status'] === 200, "Lite sale '{$scenario['name']}' stage {$stage['stage']} was refused: " . $push['raw']);
    }
}
// An existing sale cannot be rewritten afterwards (a paid online sale cannot be pushed back to pending).
$paidScenario = null;
foreach ($guardFixture['scenarios'] as $scenario) {
    if ($scenario['name'] === 'paystack_pending_then_paid') {
        $paidScenario = $scenario;
    }
}
$rewrite = $paidScenario['stages'][1]['changes'];
$rewrite[0]['payload']['status'] = 'pending';
check($litePush($asLite($rewrite))['status'] === 422, 'A paid sale was sent back to pending.');

// A lite device gets the browser restrictions: no settlement or payout data, no LAN keys.
check(request($baseUrl, 'list_banks', 'GET', null, $liteAuth)['status'] === 403, 'A lite till could list banks.');
check(request($baseUrl, 'report_shop_license', 'POST', ['state' => 'active'], $liteAuth)['status'] === 403, 'A lite till reported the shop license.');
// The restrictions must hold even for a lite device that OWNS its shop (one that registers without an
// invite does) - there, the channel is the only thing standing in the way.
$liteStandalone = register($baseUrl, 'lite-standalone-owner', 'lite');
check($liteStandalone['status'] === 201, 'Could not register a standalone lite till.');
$liteStandaloneAuth = ['Authorization: Bearer ' . $liteStandalone['body']['api_key']];
$pdo->prepare("UPDATE shops SET business_name = 'Lite Control', subaccount_code = 'SUB_LITE', is_verified = 1 WHERE id = (SELECT shop_id FROM clients WHERE device_id = 'lite-standalone-owner')")->execute();
check(request($baseUrl, 'list_banks', 'GET', null, $liteStandaloneAuth)['status'] === 403, 'A lite till that owns its shop could list banks.');
check(request($baseUrl, 'save_settlement_details', 'POST', ['business_name' => 'x', 'settlement_type' => 'mpesa', 'bank_code' => 'MPESA', 'account_number' => '0700000000'], $liteStandaloneAuth)['status'] === 403, 'A lite till that owns its shop could change payout details.');
check(request($baseUrl, 'report_shop_license', 'POST', ['state' => 'active'], $liteStandaloneAuth)['status'] === 403, 'A lite till that owns its shop reported the license.');
$ownerStatus = request($baseUrl, 'client_status', 'GET', null, $liteStandaloneAuth);
check($ownerStatus['status'] === 200 && $ownerStatus['body']['business_name'] === null && $ownerStatus['body']['subaccount_code'] === null, 'A lite till that owns its shop was shown payout details: ' . $ownerStatus['raw']);
check(request($baseUrl, 'lan_sync_credentials', 'GET', null, $liteAuth)['status'] === 403, 'A lite till was given LAN credentials.');
check(request($baseUrl, 'save_settlement_details', 'POST', ['business_name' => 'x', 'settlement_type' => 'mpesa', 'bank_code' => 'MPESA', 'account_number' => '0700000000'], $liteAuth)['status'] === 403, 'A lite till could change payout details.');
$liteStatus = request($baseUrl, 'client_status', 'GET', null, $liteAuth);
check($liteStatus['status'] === 200 && array_key_exists('subaccount_code', $liteStatus['body']) && $liteStatus['body']['subaccount_code'] === null, 'A lite till was shown payout details: ' . $liteStatus['raw']);

// The full app (native) is NOT held to the guard: it can still write anything, as before.
$nativeStamp = gmdate('Y-m-d\TH:i:s') . '.500000Z';
$nativeRow = ['id' => 'native-still-free', 'localRev' => ++$nativeRevision, 'updatedAt' => $nativeStamp, 'createdByDeviceId' => 'lite-shop-native-owner', 'name' => 'Anything'];
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [['table_name' => 'categories', 'row_id' => 'native-still-free', 'local_rev' => $nativeRevision, 'updated_at' => $nativeStamp, 'payload' => $nativeRow]]], $liteOwnerAuth)['status'] === 200, 'The sync guard got in the way of a normal device.');
// ---- lite_catalog: the current shop, by the app's own merge rules ------------------------------------------
$byName = static function (string $scenarioName, string $productName) use ($guardFixture): array {
    foreach ($guardFixture['scenarios'] as $scenario) {
        if ($scenario['name'] === $scenarioName) {
            foreach ($scenario['context']['products'] as $product) {
                if ($product['name'] === $productName) {
                    return $product;
                }
            }
        }
    }
    throw new RuntimeException("No $productName in $scenarioName.");
};
$nativePush = static function (string $table, array $payload) use ($baseUrl, $liteOwnerAuth, &$nativeRevision): array {
    $payload['createdByDeviceId'] = 'lite-shop-native-owner';
    $payload['localRev'] = ++$nativeRevision;
    return request($baseUrl, 'push_changes', 'POST', ['changes' => [['table_name' => $table, 'row_id' => $payload['id'], 'local_rev' => $payload['localRev'], 'updated_at' => $payload['updatedAt'], 'payload' => $payload]]], $liteOwnerAuth);
};
$widget = $byName('cash_sale_two_lines', 'Widget');
$gadget = $byName('cash_sale_two_lines', 'Gadget');
$cancelledWidget = $byName('paystack_pending_then_cancelled', 'Widget');
$deletedWidget = $byName('wholesale_sale', 'Widget');

check(request($baseUrl, 'lite_catalog', 'GET', null, $liteOwnerAuth)['status'] === 403, 'A normal device was given the lite catalog.');
check(request($baseUrl, 'lite_catalog', 'GET', null, [])['status'] === 401, 'The lite catalog was readable with no key.');

$purchase = ['id' => '00000000-0000-4000-8000-0000000000c1', 'createdAt' => $widget['createdAt'], 'updatedAt' => $widget['updatedAt'], 'deletedAt' => null, 'syncState' => 'local_only', 'productId' => $widget['id'], 'userId' => null, 'movementType' => 'purchase', 'quantity' => 50, 'note' => null];
check($nativePush('stock_movements', $purchase)['status'] === 200, 'Could not record the purchase.');
// Newest wins, whatever order the versions arrive in: a newer name first, then an OLDER one arriving later.
$newer = array_merge($gadget, ['name' => 'Gadget Renamed', 'updatedAt' => gmdate('Y-m-d\TH:i:s') . '.100000Z']);
$older = array_merge($gadget, ['name' => 'Gadget Ancient', 'updatedAt' => '2026-01-01T00:00:00.000000Z']);
check($nativePush('products', $newer)['status'] === 200 && $nativePush('products', $older)['status'] === 200, 'Could not rename the product.');
// A deleted product is not listed.
$deleted = array_merge($deletedWidget, ['deletedAt' => gmdate('Y-m-d\TH:i:s') . '.200000Z', 'updatedAt' => gmdate('Y-m-d\TH:i:s') . '.200000Z']);
check($nativePush('products', $deleted)['status'] === 200, 'Could not delete the product.');

$catalog = request($baseUrl, 'lite_catalog', 'GET', null, $liteAuth);
check($catalog['status'] === 200 && ($catalog['body']['success'] ?? false) === true, 'The lite catalog was not served: ' . $catalog['raw']);
$productsById = [];
foreach ($catalog['body']['products'] as $listed) {
    $productsById[$listed['id']] = $listed;
}
check(($productsById[$widget['id']]['stockQty'] ?? null) === 48, 'Stock is not the sum of the movements (50 bought - 2 sold): ' . json_encode($productsById[$widget['id']] ?? null));
check(($productsById[$cancelledWidget['id']]['stockQty'] ?? null) === 0, 'A cancelled sale did not put its stock back (-2 + 2).');
check(($productsById[$gadget['id']]['stockQty'] ?? null) === -1, 'Stock with only a sale recorded should be -1.');
check(($productsById[$gadget['id']]['name'] ?? '') === 'Gadget Renamed', 'The newest version did not win: ' . json_encode($productsById[$gadget['id']] ?? null));
check(!isset($productsById[$deletedWidget['id']]), 'A deleted product was listed.');
check(($productsById[$widget['id']]['retailPriceCents'] ?? null) === $widget['retailPriceCents'] && ($productsById[$widget['id']]['costPriceCents'] ?? null) === $widget['costPriceCents'], 'A product price was not carried over.');
$cashierListed = false;
foreach ($catalog['body']['users'] as $listedUser) {
    $cashierListed = $cashierListed || ($listedUser['id'] === $cashScenario['context']['cashierId'] && $listedUser['passwordHash'] !== '');
}
check($cashierListed, 'The cashier was not listed with a password hash to check a login against.');
check((int) $catalog['body']['cursor'] === (int) $pdo->query('SELECT MAX(id) FROM sync_changes WHERE shop_id = (SELECT shop_id FROM clients WHERE device_id = \'lite-till-device\')')->fetchColumn(), 'The catalog cursor is not the newest sync id.');
check(array_key_exists('license', $catalog['body']) && array_key_exists('server_time', $catalog['body']), 'The licence view and server time are missing.');
// ---- lite_report: the shop's Reports for the lite till ----------------------------------------------------------
check(request($baseUrl, 'lite_report', 'GET', null, $liteOwnerAuth)['status'] === 403, 'A normal device was given the lite report.');
check(request($baseUrl, 'lite_report', 'GET', null, [])['status'] === 401, 'The lite report was readable with no key.');
// request() puts the whole action in one encoded parameter, so a report with a period needs its own small caller.
$reportRequest = static function (array $query, array $headers) use ($baseUrl): array {
    $ch = curl_init($baseUrl . '?' . http_build_query(array_merge(['action' => 'lite_report'], $query)));
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => $raw];
};
$goodRange = ['from_utc' => '2000-01-01T00:00:00.000Z', 'to_utc' => '2100-01-01T00:00:00.000Z', 'from_date' => '2026-01-01', 'to_date' => '2026-01-31'];
foreach ([[], array_merge($goodRange, ['from_utc' => 'yesterday']), array_merge($goodRange, ['to_date' => '31/01/2026']), array_merge($goodRange, ['to_utc' => '1999-01-01T00:00:00.000Z']),
          array_merge($goodRange, ['to_date' => '2026-01-01']), array_merge($goodRange, ['to_date' => '2028-01-01'])] as $badQuery) {
    check($reportRequest($badQuery, $liteAuth)['status'] === 422, 'A bad report period was accepted: ' . json_encode($badQuery));
}
$report = $reportRequest($goodRange, $liteAuth);
check($report['status'] === 200 && ($report['body']['success'] ?? false) === true, 'The lite report was not served: ' . $report['raw']);
$figures = $report['body']['report'] ?? [];
foreach (['salesTotalCents', 'transactionCount', 'cashReceivedCents', 'mpesaCents', 'expensesTotalCents', 'grossProfitCents', 'netProfitCents', 'grandTotalCents', 'sales', 'expenses'] as $key) {
    check(array_key_exists($key, $figures), "The lite report has no $key.");
}
// The sales the lite till pushed above are paid sales of THIS shop: they are in the report, and only paid ones.
$paidInDb = (int) $pdo->query("SELECT COUNT(DISTINCT row_id) FROM sync_changes WHERE shop_id = (SELECT shop_id FROM clients WHERE device_id = 'lite-till-device') AND table_name = 'sales'")->fetchColumn();
check($figures['transactionCount'] >= 1 && $figures['transactionCount'] <= $paidInDb, 'The report counts ' . $figures['transactionCount'] . ' sales of ' . $paidInDb . ' recorded.');
check($figures['salesTotalCents'] > 0, 'The report has no sales money.');
check($figures['cashReceivedCents'] + $figures['mpesaCents'] <= $figures['salesTotalCents'], 'Cash plus M-Pesa is more than the sales total.');
foreach ($figures['sales'] as $listed) {
    check($listed['status'] === 'paid', 'A sale that is not paid was listed.');
}
check($figures['grandTotalCents'] === $figures['salesTotalCents'] - $figures['expensesTotalCents'], 'Grand total is not sales minus expenses.');
// Another shop sees none of it.
$strangerReport = $reportRequest($goodRange, $liteStandaloneAuth);
check($strangerReport['status'] === 200 && $strangerReport['body']['report']['transactionCount'] === 0 && $strangerReport['body']['report']['salesTotalCents'] === 0, 'Another shop was shown this shop\'s sales: ' . $strangerReport['raw']);
echo "Lite till tests passed: real sales accepted, tampered sales refused and not stored, restrictions applied, reports served.\n";
echo "Platform integration tests passed.\n";

// Photos are private, validated, and attached to the same message transaction.
$photoReply = request($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => '', 'attachments' => [['data' => $png]]], ['Authorization: Bearer ' . $supportKey]);
check($photoReply['status'] === 200, 'Photo-only reply failed: ' . $photoReply['raw']);
$photoThread = $supportThread($supportKey, $ticketId)['body'];
$lastMessage = end($photoThread['messages']);
check(count($lastMessage['attachments']) === 1, 'Photo metadata missing.');
$photoId = $lastMessage['attachments'][0]['id'];
$readPhoto = static function (array $headers, string $action = 'support_attachment') use ($baseUrl, $photoId): array {
    $ch = curl_init($baseUrl . '?action=' . $action . '&id=' . $photoId);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $bytes = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $status, 'bytes' => $bytes];
};
check($readPhoto([])['status'] === 401, 'Anonymous photo access was allowed.');
check($readPhoto(['Authorization: Bearer ' . $otherShop['body']['api_key']])['status'] === 404, 'Cross-shop photo access was allowed.');
check($readPhoto(['Authorization: Bearer ' . $supportKey])['bytes'] === base64_decode($png), 'Photo bytes changed.');
check($readPhoto(['X-Admin-Secret: ' . $adminSecret], 'admin_support_attachment')['status'] === 200, 'Admin cannot read photo.');
foreach ([['data' => base64_encode('<svg onload="alert(1)"></svg>')], ['data' => 'bad base64!']] as $badPhoto) {
    check(request($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Unsafe', 'attachments' => [$badPhoto]], ['Authorization: Bearer ' . $supportKey])['status'] === 422, 'Invalid image accepted.');
}
check(request($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Too many', 'attachments' => array_fill(0, 5, ['data' => $png])], ['Authorization: Bearer ' . $supportKey])['status'] === 422, 'Too many photos accepted.');
echo "Support photo access and validation tests passed.\n";
