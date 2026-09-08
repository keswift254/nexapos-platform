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

function register(string $baseUrl, string $deviceId): array
{
    return request($baseUrl, 'register_device', 'POST', [
        'device_id' => $deviceId,
        'device_label' => $deviceId,
        'registration_secret' => 'secret-' . $deviceId,
    ]);
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
$peerInvite = request($baseUrl, 'generate_invite', 'POST', [], ['Authorization: Bearer ' . $joinedKey]);
check($peerInvite['status'] === 403, 'A joined device must not invite other devices.');
$membership = request($baseUrl, 'client_status', 'GET', null, ['Authorization: Bearer ' . $joinedKey]);
check($membership['status'] === 200 && (int) ($membership['body']['shop_id'] ?? 0) > 0,
    'Client status must identify the shop for interrupted-change recovery.');
$joinedLeave = request($baseUrl, 'leave_shop', 'POST', [], ['Authorization: Bearer ' . $joinedKey]);
check($joinedLeave['status'] === 200, 'A non-owner device must be able to leave a shared shop safely.');
$leftMembership = request($baseUrl, 'client_status', 'GET', null, ['Authorization: Bearer ' . $joinedKey]);
check($leftMembership['status'] === 200 && ($leftMembership['body']['is_owner'] ?? false) === true,
    'A departed device must own its new empty shop.');

$oversized = array_fill(0, 1001, []);
$tooLarge = request($baseUrl, 'push_changes', 'POST', ['changes' => $oversized], ['Authorization: Bearer ' . $ownerKey]);
check($tooLarge['status'] === 413, 'Legacy sync hard ceiling was not enforced.');

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
    ->execute([$ownerId, 1, '2026-01-01T00:00:00Z', '{"id":"compact-row"}']);
$pdo->prepare("INSERT INTO sync_changes (shop_id, table_name, row_id, device_id, local_rev, updated_at, payload, received_at)
    VALUES ((SELECT shop_id FROM clients WHERE id = ?), 'categories', 'compact-row', 'owner-device', ?, ?, ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY))")
    ->execute([$ownerId, 2, '2026-01-02T00:00:00Z', '{"id":"compact-row"}']);
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

echo "Platform integration tests passed.\n";
