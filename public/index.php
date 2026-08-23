<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Platform\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\InviteCode;
use Platform\Services\PaystackClient;

/**
 * The 10 SyncedColumns tables on the phone (see _syncedTableNames in
 * nexapos_mobile's database.dart) - kept as an explicit allow-list here
 * so push_changes can never be used to smuggle rows into an arbitrary
 * table name.
 */
const SYNCED_TABLE_NAMES = [
    'roles', 'users', 'categories', 'products', 'sales', 'sale_items',
    'expenses', 'stock_movements', 'payment_records', 'business_settings',
];

function jsonResponse(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function requestBody(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

set_exception_handler(function (\Throwable $e): void {
    error_log('[nexapos_platform] ' . $e->getMessage());
    jsonResponse(['success' => false, 'status' => false, 'message' => 'Server error.'], 500);
});

$pdo = Database::connection();
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// Render's health check hits this - deliberately goes through
// Database::connection() above rather than skipping it, so a deploy
// only reports healthy once the DB is actually reachable, not just PHP.
if ($action === 'health' && $method === 'GET') {
    jsonResponse(['success' => true, 'service' => 'nexapos_platform']);
}

if ($action === 'register_device' && $method === 'POST') {
    $body = requestBody();
    $deviceId = trim((string) ($body['device_id'] ?? ''));
    $deviceLabel = trim((string) ($body['device_label'] ?? '')) ?: 'Unnamed device';
    if ($deviceId === '') {
        jsonResponse(['success' => false, 'message' => 'device_id is required.'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE device_id = ?');
    $stmt->execute([$deviceId]);
    $existing = $stmt->fetch();

    $withinGrace = $existing
        && $existing['status'] === 'pending_settlement'
        && (time() - strtotime((string) $existing['created_at'])) < 600;

    if ($existing && !$withinGrace) {
        jsonResponse(['success' => false, 'message' => 'This device is already registered.'], 409);
    }

    $apiKey = bin2hex(random_bytes(32));
    $apiKeyHash = hash('sha256', $apiKey);

    if ($existing) {
        $update = $pdo->prepare('UPDATE clients SET device_label = ?, api_key_hash = ? WHERE id = ?');
        $update->execute([$deviceLabel, $apiKeyHash, $existing['id']]);
    } else {
        // Every device starts as the sole member of a brand-new shop -
        // joining an EXISTING shop is a separate, authenticated step
        // (join_shop) done after registration, not a parameter here.
        // See join_shop's own comment for why it can't live in this
        // endpoint: the 409 above fires unconditionally for any device
        // outside its 10-minute grace window, before an invite code
        // would ever be read.
        $pdo->exec('INSERT INTO shops () VALUES ()');
        $shopId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO clients (device_id, device_label, api_key_hash, shop_id) VALUES (?, ?, ?, ?)');
        $insert->execute([$deviceId, $deviceLabel, $apiKeyHash, $shopId]);
    }

    jsonResponse(['success' => true, 'api_key' => $apiKey], 201);
}

if ($action === 'join_shop' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    $body = requestBody();
    $code = strtoupper(trim((string) ($body['invite_code'] ?? '')));
    if ($code === '') {
        jsonResponse(['success' => false, 'message' => 'invite_code is required.'], 422);
    }

    // Shop-switching isn't supported once a shop has genuine co-tenants:
    // moving a device out from under peers that rely on its pushed rows
    // would orphan that data and make everyone's cursors meaningless
    // against the new shop's independent id sequence. Reject outright
    // rather than silently half-migrate.
    //
    // A solo device's OWN sync history doesn't trigger this - every
    // device pushes its own seed data (roles, business settings) on its
    // very first sync cycle, before a user ever gets a chance to enter
    // an invite code, so gating on "any sync_changes at all" would make
    // joining fail for essentially every real device. No peer has ever
    // pulled a solo device's rows, so nothing is orphaned by moving it.
    $coTenants = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE shop_id = ?');
    $coTenants->execute([$client['shop_id']]);
    if ((int) $coTenants->fetchColumn() > 1) {
        jsonResponse(['success' => false, 'message' => 'This device already belongs to a shop with other devices.'], 409);
    }

    // Atomic claim: an UPDATE that only succeeds once, so two concurrent
    // redemptions of the same code can never both pass (no separate
    // SELECT-then-UPDATE race).
    $claim = $pdo->prepare('UPDATE shop_invites SET used_at = UTC_TIMESTAMP() WHERE code = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $claim->execute([$code]);
    if ($claim->rowCount() !== 1) {
        jsonResponse(['success' => false, 'message' => 'Invalid or expired invite code.'], 422);
    }

    $invite = $pdo->prepare('SELECT shop_id FROM shop_invites WHERE code = ?');
    $invite->execute([$code]);
    $newShopId = (int) $invite->fetchColumn();

    $update = $pdo->prepare('UPDATE clients SET shop_id = ? WHERE id = ?');
    $update->execute([$newShopId, $client['id']]);

    jsonResponse(['success' => true, 'shop_id' => $newShopId]);
}

if ($action === 'generate_invite' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    $platformConfig = require __DIR__ . '/../config/platform.php';
    $expiryMinutes = (int) $platformConfig['sync_invite_expiry_minutes'];

    $code = InviteCode::generate($pdo);
    $insert = $pdo->prepare("INSERT INTO shop_invites (shop_id, code, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))");
    $insert->execute([$client['shop_id'], $code, $expiryMinutes]);

    $expiresAt = $pdo->prepare('SELECT expires_at FROM shop_invites WHERE code = ?');
    $expiresAt->execute([$code]);

    jsonResponse(['success' => true, 'code' => $code, 'expires_at' => $expiresAt->fetchColumn()]);
}

if ($action === 'push_changes' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    $body = requestBody();
    $changes = $body['changes'] ?? [];
    if (!is_array($changes)) {
        jsonResponse(['success' => false, 'message' => 'changes must be an array.'], 422);
    }

    $insert = $pdo->prepare('
        INSERT INTO sync_changes (shop_id, table_name, row_id, device_id, local_rev, updated_at, payload)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    $pdo->beginTransaction();
    try {
        // One row at a time, in the exact order the caller sent them -
        // load-bearing, not stylistic: local_rev is one counter shared
        // across every table on a device, so a parent row (e.g. a
        // category) always has a lower rev than a child that references
        // it (e.g. a product). Preserving push order preserves that
        // invariant for every other device's pull.
        foreach ($changes as $change) {
            $tableName = (string) ($change['table_name'] ?? '');
            $rowId = (string) ($change['row_id'] ?? '');
            $localRev = (int) ($change['local_rev'] ?? 0);
            $updatedAt = (string) ($change['updated_at'] ?? '');
            $payload = $change['payload'] ?? null;
            if (!in_array($tableName, SYNCED_TABLE_NAMES, true) || $rowId === '' || $updatedAt === '' || !is_array($payload)) {
                throw new \RuntimeException('Malformed change entry.');
            }
            $insert->execute([
                $client['shop_id'], $tableName, $rowId, $client['device_id'], $localRev, $updatedAt,
                json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Could not record changes: ' . $e->getMessage()], 422);
    }

    jsonResponse(['success' => true, 'count' => count($changes)]);
}

if ($action === 'pull_changes' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    $since = (int) ($_GET['since'] ?? 0);

    $stmt = $pdo->prepare('
        SELECT id, table_name, row_id, device_id, local_rev, updated_at, payload
        FROM sync_changes
        WHERE shop_id = ? AND id > ?
        ORDER BY id ASC
        LIMIT 500
    ');
    $stmt->execute([$client['shop_id'], $since]);
    $rows = $stmt->fetchAll();

    $changes = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'table_name' => $row['table_name'],
            'row_id' => $row['row_id'],
            'device_id' => $row['device_id'],
            'local_rev' => (int) $row['local_rev'],
            'updated_at' => $row['updated_at'],
            'payload' => json_decode($row['payload'], true),
        ];
    }, $rows);

    $nextCursor = $changes === [] ? $since : end($changes)['id'];

    jsonResponse(['success' => true, 'changes' => $changes, 'next_cursor' => $nextCursor, 'has_more' => count($rows) === 500]);
}

if ($action === 'save_settlement_details' && $method === 'POST') {
    $client = Auth::requireClient($pdo);

    $body = requestBody();
    $businessName = trim((string) ($body['business_name'] ?? ''));
    $settlementType = trim((string) ($body['settlement_type'] ?? ''));
    $bankCode = trim((string) ($body['bank_code'] ?? ''));
    $accountNumber = trim((string) ($body['account_number'] ?? ''));

    if ($businessName === '' || !in_array($settlementType, ['bank', 'mpesa'], true) || $bankCode === '' || $accountNumber === '') {
        jsonResponse(['success' => false, 'message' => 'business_name, settlement_type, bank_code, and account_number are all required.'], 422);
    }

    $platformConfig = require __DIR__ . '/../config/platform.php';
    $percentageCharge = (float) $platformConfig['default_percentage_charge'];
    $existingSubaccountCode = (string) ($client['subaccount_code'] ?? '');

    try {
        $paystack = new PaystackClient();
        $result = $existingSubaccountCode !== ''
            ? $paystack->updateSubaccount($existingSubaccountCode, $businessName, $bankCode, $accountNumber, $percentageCharge)
            : $paystack->createSubaccount($businessName, $bankCode, $accountNumber, $percentageCharge);
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Could not reach Paystack: ' . $e->getMessage()], 502);
    }

    if (($result['body']['status'] ?? false) !== true) {
        jsonResponse(['success' => false, 'message' => (string) ($result['body']['message'] ?? 'Paystack rejected the settlement details.')], 422);
    }

    $data = $result['body']['data'] ?? [];
    $subaccountCode = (string) ($data['subaccount_code'] ?? $existingSubaccountCode);
    $accountName = (string) ($data['account_name'] ?? '');
    $isVerified = (bool) ($data['is_verified'] ?? false);

    // Shop-scoped (not client-scoped): a shop has one settlement account
    // regardless of which device fills this form in, so every other
    // device already in this shop - and any device that joins later via
    // invite code - sees the same settled status immediately.
    $update = $pdo->prepare('
        UPDATE shops
        SET business_name = ?, settlement_type = ?, bank_code = ?, account_number = ?, account_name = ?,
            subaccount_code = ?, percentage_charge = ?, is_verified = ?
        WHERE id = ?
    ');
    $update->execute([
        $businessName, $settlementType, $bankCode, $accountNumber, $accountName,
        $subaccountCode, $percentageCharge, $isVerified ? 1 : 0, $client['shop_id'],
    ]);

    jsonResponse(['success' => true, 'subaccount_code' => $subaccountCode, 'is_verified' => $isVerified, 'account_name' => $accountName]);
}

if ($action === 'client_status' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    jsonResponse([
        'success' => true,
        'status' => $client['status'],
        'business_name' => $client['business_name'],
        'settlement_type' => $client['settlement_type'],
        'bank_code' => $client['bank_code'],
        'account_number' => $client['account_number'],
        'account_name' => $client['account_name'],
        'subaccount_code' => $client['subaccount_code'],
        'is_verified' => (bool) $client['is_verified'],
    ]);
}

if ($action === 'list_banks' && $method === 'GET') {
    Auth::requireClient($pdo);

    try {
        $result = (new PaystackClient())->listBanks();
    } catch (\Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Could not reach Paystack: ' . $e->getMessage()], 502);
    }

    // Paystack's own list has duplicate (name, code) entries - dedupe by
    // code so the phone's picker doesn't show the same option twice.
    $seen = [];
    $banks = [];
    foreach ($result['body']['data'] ?? [] as $bank) {
        $code = (string) ($bank['code'] ?? '');
        if ($code === '' || isset($seen[$code])) {
            continue;
        }
        $seen[$code] = true;
        $banks[] = ['name' => (string) ($bank['name'] ?? ''), 'code' => $code, 'type' => (string) ($bank['type'] ?? '')];
    }
    jsonResponse(['success' => true, 'banks' => $banks]);
}

if ($action === 'initialize_transaction' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    // $client['status'] is a per-device fact (registration grace window,
    // admin disable - the latter already rejected inside requireClient)
    // and no longer means "settled" now that settlement is shop-scoped -
    // subaccount_code is the only real gate, and it's the same for every
    // device in this shop regardless of which one filled the form in.
    if (empty($client['subaccount_code'])) {
        jsonResponse(['status' => false, 'message' => 'Complete payment settings before accepting Paystack payments.'], 422);
    }

    $body = requestBody();
    $amount = (int) ($body['amount'] ?? 0);
    $reference = trim((string) ($body['reference'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $currency = trim((string) ($body['currency'] ?? '')) ?: 'KES';

    // Upper bound is defense-in-depth for transactions.amount_minor
    // (a 32-bit INT column, max ~2.1 billion) - 100,000,000 minor units
    // (1,000,000 KES) is already far beyond any real single POS sale.
    if ($amount < 1 || $amount > 100_000_000 || $reference === '' || $email === '') {
        jsonResponse(['status' => false, 'message' => 'amount, reference, and email are required.'], 422);
    }

    $metadata = ['client_id' => $client['id'], 'device_label' => $client['device_label'], 'source' => 'NexaPOS'];

    try {
        $result = (new PaystackClient())->initializeTransaction($amount, $reference, $email, $currency, $client['subaccount_code'], $metadata);
    } catch (\Throwable $e) {
        jsonResponse(['status' => false, 'message' => 'Could not reach Paystack: ' . $e->getMessage()], 502);
    }

    if (($result['body']['status'] ?? false) === true) {
        $insert = $pdo->prepare('
            INSERT INTO transactions (client_id, reference, amount_minor, currency, subaccount_code)
            VALUES (?, ?, ?, ?, ?)
        ');
        $insert->execute([$client['id'], $reference, $amount, $currency, $client['subaccount_code']]);
    }

    jsonResponse($result['body'], $result['http_code']);
}

if ($action === 'verify_transaction' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    $reference = trim((string) ($_GET['reference'] ?? ''));
    if ($reference === '') {
        jsonResponse(['status' => false, 'message' => 'reference is required.'], 422);
    }

    // Scoped to the calling client - a reference that exists but belongs
    // to a different client must look identical to one that doesn't
    // exist at all. This is the fix for the cross-client verify leak:
    // Paystack's own verify endpoint has no concept of subaccount
    // ownership, so that check has to happen here, before ever calling
    // Paystack, not after.
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE reference = ? AND client_id = ?');
    $stmt->execute([$reference, $client['id']]);
    $transaction = $stmt->fetch();
    if (!$transaction) {
        jsonResponse(['status' => false, 'message' => 'Transaction not found.'], 404);
    }

    try {
        $result = (new PaystackClient())->verifyTransaction($reference);
    } catch (\Throwable $e) {
        jsonResponse(['status' => false, 'message' => 'Could not reach Paystack: ' . $e->getMessage()], 502);
    }

    $paystackStatus = strtolower((string) ($result['body']['data']['status'] ?? ''));
    if ($paystackStatus === 'success') {
        $localStatus = 'verified_success';
    } elseif (in_array($paystackStatus, ['failed', 'abandoned', 'reversed'], true)) {
        $localStatus = 'verified_failed';
    } else {
        $localStatus = null; // still pending - leave the local record as 'initialized'
    }
    if ($localStatus !== null) {
        $update = $pdo->prepare('UPDATE transactions SET status = ?, verified_at = NOW() WHERE id = ?');
        $update->execute([$localStatus, $transaction['id']]);
    }

    jsonResponse($result['body'], $result['http_code']);
}

jsonResponse(['success' => false, 'status' => false, 'message' => 'Unknown action.'], 404);
