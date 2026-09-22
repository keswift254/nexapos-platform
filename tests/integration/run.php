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

echo "Platform integration tests passed.\n";
