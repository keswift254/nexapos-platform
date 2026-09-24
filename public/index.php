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
use Platform\Services\PaymentReconciler;
use Platform\Services\IntaSendClient;
use Platform\Services\IntaSendReconciler;
use Platform\Services\MaintenanceService;
use Platform\Services\SyncSnapshot;

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

/**
 * Same case-insensitive-header gotcha Auth::authorizationHeader()
 * already documents: $_SERVER['HTTP_...'] is unset under some
 * Apache/PHP configs, and a literal getallheaders() lookup misses
 * lowercase header names some clients send. Mirrors nexapos_license's
 * own headerValue() exactly.
 */
function headerValue(string $name): string
{
    $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$server])) {
        return (string) $_SERVER[$server];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return (string) $value;
            }
        }
    }
    return '';
}

/** Mirrors nexapos_license's own requireAdmin exactly - gates the one
 * cross-shop admin action this service has (list_all_devices). */
function requireAdmin(array $platformConfig): void
{
    $secret = trim(headerValue('X-Admin-Secret'));
    $expected = (string) $platformConfig['admin_secret'];
    if ($expected === '' || !hash_equals($expected, $secret)) {
        jsonResponse(['success' => false, 'message' => 'Invalid or missing admin secret.'], 401);
    }
}

/**
 * Lazily creates this shop's IntaSend WORKING wallet on first use, so
 * collecting a payment never needs a separate "set up IntaSend" step the
 * way Paystack's subaccount does - see IntaSendClient::createWallet's
 * doc for why no bank details are needed up front. Idempotent: once
 * shops.intasend_wallet_id is set, every later call just returns it.
 */
function ensureIntaSendWallet(PDO $pdo, array $client, IntaSendClient $intasend): string
{
    $existing = trim((string) ($client['intasend_wallet_id'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }

    $label = trim((string) ($client['business_name'] ?? '')) ?: ('NexaPOS Shop #' . $client['shop_id']);
    $result = $intasend->createWallet($label);
    $walletId = trim((string) ($result['body']['id'] ?? ''));
    if ($walletId === '') {
        throw new \RuntimeException('IntaSend did not return a wallet id.');
    }

    $update = $pdo->prepare('UPDATE shops SET intasend_wallet_id = ? WHERE id = ?');
    $update->execute([$walletId, $client['shop_id']]);
    return $walletId;
}

set_exception_handler(function (\Throwable $e): void {
    error_log('[nexapos_platform] ' . $e->getMessage());
    jsonResponse(['success' => false, 'status' => false, 'message' => 'Server error.'], 500);
});

$pdo = Database::connection();
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$platformConfig = require __DIR__ . '/../config/platform.php';

// Every other action here is called by the Flutter app (not subject to
// CORS). The browser-hosted admin dashboard is granted only its
// configured origin; holding the admin secret remains the real auth
// boundary, while the origin allow-list reduces browser exposure.
$origin = trim(headerValue('Origin'));
$allowedOrigins = $platformConfig['cors_allowed_origins'] ?? [];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Secret');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($method === 'OPTIONS') {
    if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
        jsonResponse(['success' => false, 'message' => 'Origin is not allowed.'], 403);
    }
    http_response_code(204);
    exit;
}

if ($action === 'paystack_webhook' && $method === 'POST') {
    $rawPayload = (string) file_get_contents('php://input');
    if ($rawPayload === '' || strlen($rawPayload) > 1_048_576) {
        jsonResponse(['success' => false, 'message' => 'Invalid webhook payload.'], 422);
    }
    if (!PaymentReconciler::hasValidSignature(
        $rawPayload,
        headerValue('X-Paystack-Signature'),
        (string) $platformConfig['paystack_secret_key']
    )) {
        jsonResponse(['success' => false, 'message' => 'Invalid webhook signature.'], 401);
    }

    try {
        $event = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new \RuntimeException('Webhook payload must be an object.');
        }
        $paystack = new PaystackClient();
        $reconciler = new PaymentReconciler($pdo, [$paystack, 'verifyTransaction']);
        $result = $reconciler->handleWebhook($event, $rawPayload);
    } catch (\JsonException $e) {
        jsonResponse(['success' => false, 'message' => 'Invalid webhook JSON.'], 422);
    } catch (\Throwable $e) {
        error_log('[nexapos_platform] Paystack webhook failed: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Webhook processing will be retried.'], 503);
    }

    jsonResponse(['success' => true, 'outcome' => $result['outcome']]);
}

if ($action === 'intasend_webhook' && $method === 'POST') {
    $rawPayload = (string) file_get_contents('php://input');
    if ($rawPayload === '' || strlen($rawPayload) > 1_048_576) {
        jsonResponse(['success' => false, 'message' => 'Invalid webhook payload.'], 422);
    }

    try {
        $event = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new \RuntimeException('Webhook payload must be an object.');
        }
    } catch (\JsonException $e) {
        jsonResponse(['success' => false, 'message' => 'Invalid webhook JSON.'], 422);
    }

    if (!IntaSendReconciler::hasValidChallenge(
        (string) ($event['challenge'] ?? ''),
        (string) $platformConfig['intasend_webhook_challenge']
    )) {
        jsonResponse(['success' => false, 'message' => 'Invalid webhook challenge.'], 401);
    }

    try {
        $intasend = new IntaSendClient();
        $reconciler = new IntaSendReconciler($pdo, [$intasend, 'status']);
        $result = $reconciler->handleWebhook($event, $rawPayload);
    } catch (\Throwable $e) {
        error_log('[nexapos_platform] IntaSend webhook failed: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Webhook processing will be retried.'], 503);
    }

    jsonResponse(['success' => true, 'outcome' => $result['outcome']]);
}

// Render's health check hits this - deliberately goes through
// Database::connection() above rather than skipping it, so a deploy
// only reports healthy once the DB is actually reachable, not just PHP.
if ($action === 'health' && $method === 'GET') {
    // db_host is safe to expose (a hostname isn't a secret) and is the
    // fastest way to confirm which database is actually live after a
    // manual switch to/from the standby - see the standby runbook. Not
    // the full DSN/credentials, just enough to tell primary from
    // standby apart at a glance.
    $dbConfig = require __DIR__ . '/../config/config.php';
    jsonResponse(['success' => true, 'service' => 'nexapos_platform', 'db_host' => $dbConfig['db']['host']]);
}

/**
 * Admin action - every device across every shop, for the vendor-wide
 * device dashboard. Unlike list_devices (owner-only, one shop's own
 * peers), this deliberately crosses shop boundaries, so it's gated by
 * admin_secret rather than a device's own Bearer token - there is no
 * per-shop "owner" concept that should ever see other shops' devices.
 */
if ($action === 'list_all_devices' && $method === 'GET') {
    $platformConfig = require __DIR__ . '/../config/platform.php';
    requireAdmin($platformConfig);

    $stmt = $pdo->query('
        SELECT clients.id, clients.device_id, clients.device_label, clients.shop_id,
               clients.is_owner, clients.status, clients.last_seen_at, clients.created_at,
               shops.business_name
        FROM clients
        JOIN shops ON shops.id = clients.shop_id
        ORDER BY clients.shop_id, clients.id
    ');
    jsonResponse(['success' => true, 'devices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'run_maintenance' && $method === 'POST') {
    requireAdmin($platformConfig);
    $deleted = (new MaintenanceService($pdo, $platformConfig))->run();
    jsonResponse(['success' => true, 'deleted' => $deleted]);
}

/**
 * Admin action - the dashboard's "Delete" button. Same effect as the
 * existing owner-only revoke_device (status -> 'disabled', which
 * Auth::requireClient already enforces everywhere), not a real SQL
 * DELETE - clients.id is referenced by transactions.client_id via a
 * foreign key, so a hard delete would either fail outright for any
 * device that ever took a real payment or orphan its transaction
 * history, and would strand a shop with no owner at all if the
 * deleted device happened to be the founding one. Unlike
 * revoke_device, not scoped to the caller's own shop (there is no
 * "caller's own shop" for an admin_secret-authenticated request) and
 * has no self-revoke guard (an admin isn't authenticating as a
 * device), so it can target any device in any shop.
 */
if ($action === 'admin_revoke_device' && $method === 'POST') {
    $platformConfig = require __DIR__ . '/../config/platform.php';
    requireAdmin($platformConfig);

    $body = requestBody();
    $clientId = (int) ($body['client_id'] ?? 0);
    if ($clientId <= 0) {
        jsonResponse(['success' => false, 'message' => 'client_id is required.'], 422);
    }
    $update = $pdo->prepare("UPDATE clients SET status = 'disabled' WHERE id = ? AND status != 'disabled'");
    $update->execute([$clientId]);
    if ($update->rowCount() !== 1) {
        jsonResponse(['success' => false, 'message' => 'Device not found, or already disabled.'], 404);
    }
    jsonResponse(['success' => true]);
}

/**
 * Same effect as admin_revoke_device, keyed by device_id instead of this
 * service's own client_id - lets nexapos_license's revoke action cut off
 * a device's platform/sync access when its license gets revoked,
 * without first needing a round trip to look up its client_id. Not a
 * general public action - still requireAdmin-gated, same as
 * admin_revoke_device, since a caller that doesn't hold the shared
 * admin secret has no business disabling any device this way.
 */
if ($action === 'admin_revoke_device_by_device_id' && $method === 'POST') {
    $platformConfig = require __DIR__ . '/../config/platform.php';
    requireAdmin($platformConfig);

    $body = requestBody();
    $deviceId = trim((string) ($body['device_id'] ?? ''));
    if ($deviceId === '') {
        jsonResponse(['success' => false, 'message' => 'device_id is required.'], 422);
    }
    $update = $pdo->prepare("UPDATE clients SET status = 'disabled' WHERE device_id = ? AND status != 'disabled'");
    $update->execute([$deviceId]);
    if ($update->rowCount() !== 1) {
        jsonResponse(['success' => false, 'message' => 'Device not found, not registered for sync, or already disabled.'], 404);
    }
    jsonResponse(['success' => true]);
}

/**
 * Inverse of admin_revoke_device_by_device_id - lets nexapos_license's
 * unrevoke action give a device its platform/sync access back once its
 * license has been reinstated (a revoke by mistake, or a dispute that got
 * resolved). Only ever touches a device that is currently 'disabled', and
 * puts it back to 'active' - the state a normal, in-good-standing device is
 * in - so this can never disturb a device that was never revoked. Same
 * requireAdmin gate as the revoke it undoes: nothing that lacks the shared
 * admin secret has any business re-enabling a disabled device.
 */
if ($action === 'admin_restore_device_by_device_id' && $method === 'POST') {
    $platformConfig = require __DIR__ . '/../config/platform.php';
    requireAdmin($platformConfig);

    $body = requestBody();
    $deviceId = trim((string) ($body['device_id'] ?? ''));
    if ($deviceId === '') {
        jsonResponse(['success' => false, 'message' => 'device_id is required.'], 422);
    }
    $update = $pdo->prepare("UPDATE clients SET status = 'active' WHERE device_id = ? AND status = 'disabled'");
    $update->execute([$deviceId]);
    if ($update->rowCount() !== 1) {
        jsonResponse(['success' => false, 'message' => 'Device not found, not registered for sync, or not disabled.'], 404);
    }
    jsonResponse(['success' => true]);
}

/**
 * Admin action - the dashboard's "Revoke shop" button. Disables every
 * device belonging to the given shop in one call (same 'disabled'
 * status admin_revoke_device already uses per-device) - there is no
 * separate shop-level active/inactive flag, a shop's real access is
 * entirely defined by whether any of its devices can still
 * authenticate, so cutting off a shop just means cutting off all of
 * them at once. Zero devices actually revoked (already all disabled,
 * or a shop with none) is still success, not an error - unlike
 * admin_revoke_device there's no single expected target row to miss.
 */
if ($action === 'admin_revoke_shop' && $method === 'POST') {
    $platformConfig = require __DIR__ . '/../config/platform.php';
    requireAdmin($platformConfig);

    $body = requestBody();
    $shopId = (int) ($body['shop_id'] ?? 0);
    if ($shopId <= 0) {
        jsonResponse(['success' => false, 'message' => 'shop_id is required.'], 422);
    }
    $update = $pdo->prepare("UPDATE clients SET status = 'disabled' WHERE shop_id = ? AND status != 'disabled'");
    $update->execute([$shopId]);
    jsonResponse(['success' => true, 'devices_revoked' => $update->rowCount()]);
}

if ($action === 'register_device' && $method === 'POST') {
    $body = requestBody();
    $deviceId = trim((string) ($body['device_id'] ?? ''));
    $deviceLabel = trim((string) ($body['device_label'] ?? '')) ?: 'Unnamed device';
    // Client-generated, sent on every registration attempt for this
    // device_id (first and any retry) - see clients.registration_secret_hash's
    // schema comment for why this exists. Not optional: a client too
    // old to send one simply can't use the grace-window retry path,
    // which is the intended, safer behavior, not a bug.
    $registrationSecret = trim((string) ($body['registration_secret'] ?? ''));
    // Stamped once here, on the brand-new-shop INSERT path only, and
    // never touched again - same immutable-after-creation pattern as
    // is_owner just below. An unrecognized value (or none - every native
    // build predating this field) is treated as 'native', so this is
    // opt-in for the browser build specifically, never something a
    // client can accidentally leave itself gated out of.
    $channel = ($body['channel'] ?? '') === 'browser' ? 'browser' : 'native';
    if ($deviceId === '') {
        jsonResponse(['success' => false, 'message' => 'device_id is required.'], 422);
    }

    // Concurrent first registrations can deadlock on the missing device row.
    // Retry the whole transaction so recovery always rechecks the stored secret.
    for ($registrationAttempt = 0; $registrationAttempt < 3; $registrationAttempt++) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM clients WHERE device_id = ? FOR UPDATE');
            $stmt->execute([$deviceId]);
            $existing = $stmt->fetch();

            $secretMatches = $existing
                && $registrationSecret !== ''
                && $existing['registration_secret_hash'] !== null
                && hash_equals($existing['registration_secret_hash'], hash('sha256', $registrationSecret));
            // Originally also required status = 'pending_settlement' AND being
            // within 10 minutes of created_at - both dropped, approved by the
            // user 2026-08-28. The secret match is what actually protects this
            // (a 256-bit value never transmitted anywhere except this one call
            // over HTTPS, generated once, stored only in this device's own
            // local DB - see registration_secret_hash's own comment) - the
            // extra time bound on top of an already-secret-gated retry wasn't
            // adding real protection, it was just permanently locking out any
            // device whose local secure storage lost its api_key (lost app
            // data, a botched update, a factory reset that somehow kept the
            // same local DB file) more than 10 minutes after its first
            // registration. Still unconditionally blocked for a disabled
            // (admin-revoked) device - status alone decides that, regardless of
            // secret, so revoke_device/admin_revoke_device's guarantee that
            // there's no way back in via the API is untouched.
            $canRecover = $existing && $existing['status'] !== 'disabled' && $secretMatches;

            if ($existing && !$canRecover) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'This device is already registered.'], 409);
            }

            $apiKey = bin2hex(random_bytes(32));
            $apiKeyHash = hash('sha256', $apiKey);

            if ($existing) {
                // registration_secret_hash is deliberately left untouched here.
                $update = $pdo->prepare('UPDATE clients SET device_label = ?, api_key_hash = ? WHERE id = ?');
                $update->execute([$deviceLabel, $apiKeyHash, $existing['id']]);
            } else {
                // Every device starts as the sole member of a brand-new shop;
                // joining an existing shop is a separate authenticated step.
                if ($registrationSecret === '') {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'registration_secret is required.'], 422);
                }
                $registrationSecretHash = hash('sha256', $registrationSecret);
                $pdo->exec('INSERT INTO shops () VALUES ()');
                $shopId = (int) $pdo->lastInsertId();
                $insert = $pdo->prepare('INSERT INTO clients (device_id, device_label, api_key_hash, registration_secret_hash, shop_id, is_owner, channel) VALUES (?, ?, ?, ?, ?, 1, ?)');
                $insert->execute([$deviceId, $deviceLabel, $apiKeyHash, $registrationSecretHash, $shopId, $channel]);
            }
            $pdo->commit();
            break;
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($registrationAttempt < 2 && in_array($driverCode, [1062, 1205, 1213], true)) {
                usleep(random_int(10000, 50000));
                continue;
            }
            if ((int) $e->getCode() === 23000) {
                jsonResponse(['success' => false, 'message' => 'This device is already registered.'], 409);
            }
            throw $e;
        }
    }

    jsonResponse(['success' => true, 'api_key' => $apiKey], 201);
}

/**
 * Read-only recovery helper - lets a device that still has its own
 * registration_secret (survives independently of api_key, see
 * device_meta_table.dart in nexapos_mobile) see what it's currently
 * registered as before deciding what to type, instead of only finding
 * out via a 409 on submit. Same secret-match trust boundary as
 * register_device's own recovery path. Deliberately generic on any
 * mismatch (wrong secret OR no such device_id) so this can't be used to
 * enumerate device_ids - device_id alone was never secret, the label/
 * shop info behind it is what this actually protects.
 */
if ($action === 'registration_lookup' && $method === 'POST') {
    $body = requestBody();
    $deviceId = trim((string) ($body['device_id'] ?? ''));
    $registrationSecret = trim((string) ($body['registration_secret'] ?? ''));

    $notFound = static function () {
        jsonResponse(['success' => false, 'message' => 'Not registered yet.'], 404);
    };
    if ($deviceId === '' || $registrationSecret === '') {
        $notFound();
    }

    $stmt = $pdo->prepare('
        SELECT clients.device_label, clients.status, clients.registration_secret_hash, clients.is_owner, shops.business_name
        FROM clients JOIN shops ON shops.id = clients.shop_id
        WHERE clients.device_id = ?
    ');
    $stmt->execute([$deviceId]);
    $existing = $stmt->fetch();

    $matches = $existing
        && $existing['registration_secret_hash'] !== null
        && hash_equals($existing['registration_secret_hash'], hash('sha256', $registrationSecret));
    if (!$matches) {
        $notFound();
    }

    jsonResponse([
        'success' => true,
        'device_label' => $existing['device_label'],
        'business_name' => $existing['business_name'],
        'is_disabled' => $existing['status'] === 'disabled',
        // Lets the app decide, before the person types anything, whether to offer a
        // no-code Reconnect (a device that joined someone else's shop) or point this
        // device at its license key instead (the device that founded its own shop -
        // register_device/client_status already refuse to reconnect it, but only
        // after a round trip, so the join screen could not avoid showing the button).
        'is_owner' => (bool) $existing['is_owner'],
    ]);
}

if ($action === 'join_shop' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    $body = requestBody();
    $code = strtoupper(trim((string) ($body['invite_code'] ?? '')));
    if ($code === '') {
        jsonResponse(['success' => false, 'message' => 'invite_code is required.'], 422);
    }

    // Rate limit BEFORE ever looking at the code: InviteCode's alphabet
    // (32 chars) x length (8) is only ~40 bits, guessable given enough
    // unthrottled attempts against a code that just needs to be valid
    // for its 15-minute window, not globally unique forever. Checked by
    // BOTH client_id and ip_address - register_device is free and
    // unlimited, so client_id alone resets on request for anyone
    // willing to mint a fresh device; ip_address is the actual backstop
    // against that. join_attempts only ever logs failures (see below),
    // so no legitimate device that gets its own code right the first
    // time is ever affected by this.
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $recentByClient = $pdo->prepare('SELECT COUNT(*) FROM join_attempts WHERE client_id = ? AND attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
    $recentByClient->execute([$client['id']]);
    $recentByIp = $ip === '' ? null : $pdo->prepare('SELECT COUNT(*) FROM join_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
    $recentByIp?->execute([$ip]);
    if ((int) $recentByClient->fetchColumn() >= 10 || ($recentByIp && (int) $recentByIp->fetchColumn() >= 10)) {
        jsonResponse(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429);
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
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('UPDATE shop_invites SET used_at = UTC_TIMESTAMP() WHERE code = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        $claim->execute([$code]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            $log = $pdo->prepare('INSERT INTO join_attempts (client_id, ip_address) VALUES (?, ?)');
            $log->execute([$client['id'], $ip]);
            jsonResponse(['success' => false, 'message' => 'Invalid or expired invite code.'], 422);
        }

        $invite = $pdo->prepare('SELECT shop_id FROM shop_invites WHERE code = ?');
        $invite->execute([$code]);
        $newShopId = (int) $invite->fetchColumn();

        if ($newShopId <= 0 || $newShopId === (int) $client['shop_id']) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Choose an invite for a different shop.'], 422);
        }

        $update = $pdo->prepare('UPDATE clients SET shop_id = ?, is_owner = 0 WHERE id = ?');
        $update->execute([$newShopId, $client['id']]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($newShopId <= 0) {
        $log = $pdo->prepare('INSERT INTO join_attempts (client_id, ip_address) VALUES (?, ?)');
        $log->execute([$client['id'], $ip]);
        jsonResponse(['success' => false, 'message' => 'Invalid or expired invite code.'], 422);
    }

    // is_owner reset to 0: this device is redeeming someone ELSE's
    // invite code, so by definition it didn't found the shop it's about
    // to join - whichever device generated the invite code did (or is
    // itself just another non-owner peer, if ownership was never
    // transferred - either way, this device isn't it). Without this
    // reset, a solo device that founded its own shop (is_owner=1) could
    // join_shop into a different, unrelated shop and incorrectly
    // inherit owner-only settlement rights there.
    jsonResponse(['success' => true, 'shop_id' => $newShopId]);
}

/**
 * Self-service equivalent of register_device's "brand new shop" branch,
 * for a device that's already registered and wants to detach from its
 * current shop entirely - e.g. it was set up for the wrong shop, or a
 * demo/test that's done. Same co-tenant guard as join_shop above, and
 * for the identical reason documented there: this device's already-
 * synced rows are relied on by any peer still in its CURRENT shop, so
 * leaving one that still has other devices would orphan their sync
 * state exactly the way switching into one would. A solo device (the
 * common case - it's that shop's sole, founding member) has no peers to
 * orphan, so it's always free to leave; the shop it leaves behind
 * simply becomes permanently empty (unreachable via Auth ever again,
 * since it then has zero clients) rather than orphaned-with-a-stranded-
 * owner. api_key is untouched - still the same device, same credential,
 * just a different (brand new, empty) shop_id.
 */
if ($action === 'leave_shop' && $method === 'POST') {
    $client = Auth::requireClient($pdo);

    $coTenants = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE shop_id = ?');
    $coTenants->execute([$client['shop_id']]);
    if ((int) $coTenants->fetchColumn() > 1 && (bool) $client['is_owner']) {
        jsonResponse(['success' => false, 'message' => 'The owner cannot leave while other devices belong to this shop. Remove those devices first.'], 409);
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('INSERT INTO shops () VALUES ()');
        $newShopId = (int) $pdo->lastInsertId();
        $update = $pdo->prepare('UPDATE clients SET shop_id = ?, is_owner = 1 WHERE id = ?');
        $update->execute([$newShopId, $client['id']]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    jsonResponse(['success' => true, 'shop_id' => $newShopId]);
}

if ($action === 'generate_invite' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    if (!(bool) $client['is_owner']) {
        jsonResponse(['success' => false, 'message' => 'Only the shop owner can invite devices.'], 403);
    }
    $platformConfig = require __DIR__ . '/../config/platform.php';
    $expiryMinutes = (int) $platformConfig['sync_invite_expiry_minutes'];

    $code = InviteCode::generate($pdo);
    $insert = $pdo->prepare("INSERT INTO shop_invites (shop_id, code, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))");
    $insert->execute([$client['shop_id'], $code, $expiryMinutes]);

    $expiresAt = $pdo->prepare('SELECT expires_at FROM shop_invites WHERE code = ?');
    $expiresAt->execute([$code]);

    jsonResponse(['success' => true, 'code' => $code, 'expires_at' => $expiresAt->fetchColumn()]);
}

/**
 * Lets the owner see every device currently in their shop, so they have
 * something concrete to revoke_device against. Owner-only, same
 * reasoning as save_settlement_details - device management is the other
 * action (besides settlement) where letting any peer act would be
 * meaningfully worse than the sync access every joined device already
 * needs.
 */
if ($action === 'list_devices' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    if (!$client['is_owner']) {
        jsonResponse(['success' => false, 'message' => 'Only the device that originally set up this shop can manage devices.'], 403);
    }
    $stmt = $pdo->prepare('SELECT id, device_label, is_owner, status, created_at, last_seen_at FROM clients WHERE shop_id = ? ORDER BY id');
    $stmt->execute([$client['shop_id']]);
    jsonResponse(['success' => true, 'devices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/**
 * Closes the device-revocation gap flagged in the 2026-08-25 security
 * review: until now, a lost/stolen phone or an ex-employee's device
 * stayed a fully-trusted shop peer forever - nothing anywhere could ever
 * move a client to 'disabled', even though Auth::requireClient already
 * enforces that status the moment it's set (schema.sql's clients.status
 * comment describes this as intentional, but no endpoint ever wrote it).
 * Scoped by shop_id in the UPDATE itself, not just the is_owner check,
 * so an owner can never revoke a device outside their own shop even by
 * guessing/iterating client ids.
 */
if ($action === 'revoke_device' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    if (!$client['is_owner']) {
        jsonResponse(['success' => false, 'message' => 'Only the device that originally set up this shop can manage devices.'], 403);
    }
    $body = requestBody();
    $targetId = (int) ($body['client_id'] ?? 0);
    if ($targetId === (int) $client['id']) {
        jsonResponse(['success' => false, 'message' => 'You cannot revoke the device you are currently using.'], 422);
    }
    $update = $pdo->prepare("UPDATE clients SET status = 'disabled' WHERE id = ? AND shop_id = ? AND status != 'disabled'");
    $update->execute([$targetId, $client['shop_id']]);
    if ($update->rowCount() !== 1) {
        jsonResponse(['success' => false, 'message' => 'Device not found, or already disabled.'], 404);
    }
    jsonResponse(['success' => true]);
}

if ($action === 'push_changes' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    $body = requestBody();
    $changes = $body['changes'] ?? [];
    if (!is_array($changes)) {
        jsonResponse(['success' => false, 'message' => 'changes must be an array.'], 422);
    }
    $legacyBatchLimit = max(200, min(5000, (int) ($platformConfig['sync_legacy_batch_limit'] ?? 1000)));
    if (count($changes) > $legacyBatchLimit) {
        jsonResponse(['success' => false, 'message' => "A sync batch may contain at most $legacyBatchLimit changes."], 413);
    }

    $insert = $pdo->prepare('
        INSERT INTO sync_changes (shop_id, table_name, row_id, device_id, local_rev, updated_at, payload)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $existingRevision = $pdo->prepare('
        SELECT table_name, row_id, updated_at, payload
        FROM sync_changes
        WHERE shop_id = ? AND device_id = ? AND local_rev = ?
        LIMIT 1
    ');
    $sourceClient = $pdo->prepare('
        SELECT id FROM clients
        WHERE shop_id = ? AND device_id = ? AND channel = \'native\' AND status != \'disabled\'
        LIMIT 1
    ');

    $pdo->beginTransaction();
    try {
        // One row at a time, in the exact order the caller sent them -
        // load-bearing, not stylistic: local_rev is one counter shared
        // across every table on a device, so a parent row (e.g. a
        // category) always has a lower rev than a child that references
        // it (e.g. a product). Preserving push order preserves that
        // invariant for every other device's pull.
        $shopLock = $pdo->prepare('SELECT id FROM shops WHERE id = ? FOR UPDATE');
        $shopLock->execute([$client['shop_id']]);
        $encodedBytes = 0;
        $validatedRelaySources = [];
        $requestByteLimit = max(1_048_576, min(67_108_864, (int) ($platformConfig['sync_request_payload_limit_bytes'] ?? 16_777_216)));
        foreach ($changes as $change) {
            if (!is_array($change)) {
                throw new \RuntimeException('Malformed change entry.');
            }
            $tableName = (string) ($change['table_name'] ?? '');
            $rowId = (string) ($change['row_id'] ?? '');
            $localRev = (int) ($change['local_rev'] ?? 0);
            $updatedAt = (string) ($change['updated_at'] ?? '');
            $payload = $change['payload'] ?? null;
            $sourceDeviceId = trim((string) ($change['source_device_id'] ?? $client['device_id']));
            if (!in_array($tableName, SYNCED_TABLE_NAMES, true)
                || $rowId === '' || strlen($rowId) > 40
                || $sourceDeviceId === '' || strlen($sourceDeviceId) > 64
                || $localRev < 1
                || $updatedAt === '' || strlen($updatedAt) > 40
                || !is_array($payload)
                || (string) ($payload['id'] ?? '') !== $rowId
                || (int) ($payload['localRev'] ?? 0) !== $localRev
                || (string) ($payload['createdByDeviceId'] ?? '') !== $sourceDeviceId
                || (string) ($payload['updatedAt'] ?? '') !== $updatedAt) {
                throw new \RuntimeException('Malformed change entry.');
            }
            if ($sourceDeviceId !== (string) $client['device_id']) {
                if ($client['channel'] !== 'native') {
                    throw new \RuntimeException('Browser clients cannot relay LAN changes.');
                }
                if (!isset($validatedRelaySources[$sourceDeviceId])) {
                    $sourceClient->execute([$client['shop_id'], $sourceDeviceId]);
                    if (!$sourceClient->fetchColumn()) {
                        throw new \RuntimeException('Relayed source device is not active in this shop.');
                    }
                    $validatedRelaySources[$sourceDeviceId] = true;
                }
            }
            // updated_at is what last-write-wins compares (as text) across
            // every device in the shop, so a value far in the future beats
            // every correct edit made afterwards - permanently, since
            // nobody's real clock ever catches up to it. Two ways that
            // happens for real: a PC whose dead CMOS battery reset its
            // clock to the wrong year, and someone forging it on purpose.
            // A day of tolerance covers ordinary drift and any
            // timezone-labelling slip (max UTC offset is 14h) while still
            // catching a wrong year or month; the failure is loud (the
            // device's sync error names its clock) instead of silently
            // corrupting every other device's data.
            try {
                $changeTime = new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                throw new \RuntimeException('Change has an unreadable timestamp.');
            }
            if ($changeTime->getTimestamp() > time() + 86400) {
                throw new \RuntimeException('Change is dated more than a day in the future - check this device\'s date and time.');
            }
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($encodedPayload) > 262144) {
                throw new \RuntimeException('Change payload is too large.');
            }
            $encodedBytes += strlen($encodedPayload);
            if ($encodedBytes > $requestByteLimit) {
                throw new \RuntimeException('Sync request payload is too large.');
            }
            $existingRevision->execute([$client['shop_id'], $sourceDeviceId, $localRev]);
            $existing = $existingRevision->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['table_name'] !== $tableName
                    || $existing['row_id'] !== $rowId
                    || $existing['updated_at'] !== $updatedAt
                    || json_decode((string) $existing['payload'], true) != $payload) {
                    throw new \RuntimeException('A source revision was already recorded with different content.');
                }
                continue;
            }
            $insert->execute([
                $client['shop_id'], $tableName, $rowId, $sourceDeviceId, $localRev, $updatedAt,
                $encodedPayload,
            ]);
        }
        $pdo->commit();
    } catch (\RuntimeException $e) {
        // Our own validation message (thrown just above, in this same
        // function) - safe to show verbatim, unlike a raw DB exception.
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Could not record changes: ' . $e->getMessage()], 422);
    } catch (\Throwable $e) {
        // Anything else (a constraint violation, a column-too-long
        // value, etc.) - log the real detail server-side, but don't
        // echo it back; a PDO exception message routinely includes
        // column/constraint/table names, which is recon-grade
        // information disclosure to hand an authenticated-but-possibly-
        // malicious caller for free.
        $pdo->rollBack();
        error_log('[nexapos_platform] push_changes failed: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Could not record changes.'], 422);
    }

    jsonResponse(['success' => true, 'count' => count($changes), 'recommended_batch_size' => 200]);
}

if ($action === 'lan_sync_credentials' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    if ($client['channel'] !== 'native') {
        jsonResponse(['success' => false, 'message' => 'LAN sync credentials are available only to native clients.'], 403);
    }

    $secret = $client['lan_sync_secret'];
    if (!is_string($secret) || strlen($secret) !== 32) {
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare('SELECT lan_sync_secret FROM shops WHERE id = ? FOR UPDATE');
            $select->execute([$client['shop_id']]);
            $secret = $select->fetchColumn();
            if (!is_string($secret) || strlen($secret) !== 32) {
                $secret = random_bytes(32);
                $pdo->prepare('UPDATE shops SET lan_sync_secret = ? WHERE id = ?')
                    ->execute([$secret, $client['shop_id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[nexapos_platform] LAN key creation failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Could not prepare LAN synchronization.'], 500);
        }
    }

    jsonResponse([
        'success' => true,
        'shop_id' => (int) $client['shop_id'],
        'device_id' => (string) $client['device_id'],
        'secret' => base64_encode($secret),
    ]);
}

if ($action === 'start_sync_snapshot' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    $snapshot = SyncSnapshot::start($pdo, $client);
    if (!empty($snapshot['building'])) {
        // Not an error: the app shows this text and asks again in a few seconds.
        jsonResponse(['success' => false, 'building' => true, 'message' => 'The shop download is still being prepared on the server. This can take a minute or two the first time for a shop with a long history - it will keep trying.'], 409);
    }
    jsonResponse(['success' => true] + $snapshot);
}

if ($action === 'discard_sync_snapshot' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    $body = requestBody();
    $delete = $pdo->prepare('DELETE FROM sync_snapshots WHERE id = ? AND client_id = ? AND shop_id = ?');
    $delete->execute([(string) ($body['snapshot_id'] ?? ''), $client['id'], $client['shop_id']]);
    jsonResponse(['success' => true]);
}

if ($action === 'pull_sync_snapshot' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    $snapshot = SyncSnapshot::page($pdo, $client, (string) ($_GET['snapshot_id'] ?? ''), max(0, (int) ($_GET['after'] ?? 0)));
    if ($snapshot === null) jsonResponse(['success' => false, 'message' => 'Initial sync snapshot expired. Retry to resume with a fresh snapshot.'], 410);
    jsonResponse(['success' => true] + $snapshot);
}

if ($action === 'pull_changes' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    $since = max(0, (int) ($_GET['since'] ?? 0));

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
    // Redirecting where a shop's real money gets paid out is far more
    // consequential than the sync access every joined device needs -
    // see clients.is_owner's schema comment for the full story. A
    // non-owner device (anyone who joined via invite code, including a
    // shared/leaked one) can still read settlement status via
    // client_status, just not change it.
    if (!$client['is_owner']) {
        jsonResponse(['success' => false, 'message' => 'Only the device that originally set up this shop can change settlement details.'], 403);
    }
    // Independent of is_owner above, not a replacement for it: a browser
    // build's own first-run setup could otherwise register a brand-new
    // shop and legitimately BE its owner - the channel gate exists
    // specifically so "owner" still isn't enough once the device is a
    // browser tab. See clients.channel's own schema comment.
    if ($client['channel'] === 'browser') {
        jsonResponse(['success' => false, 'message' => 'Settlement details cannot be changed from a browser session. Use the installed app on a phone or PC instead.'], 403);
    }

    $body = requestBody();
    $businessName = trim((string) ($body['business_name'] ?? ''));
    $settlementType = trim((string) ($body['settlement_type'] ?? ''));
    $bankCode = trim((string) ($body['bank_code'] ?? ''));
    $accountNumber = trim((string) ($body['account_number'] ?? ''));

    if ($businessName === '' || !in_array($settlementType, ['bank', 'mpesa'], true) || $bankCode === '' || $accountNumber === '') {
        jsonResponse(['success' => false, 'message' => 'business_name, settlement_type, bank_code, and account_number are all required.'], 422);
    }

    $platformConfig = require __DIR__ . '/../config/platform.php';
    $percentageCharge = 0.0;
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
    // A browser client never sees where a shop's money actually goes -
    // not even read-only, not even as the shop's own owner (see
    // save_settlement_details' matching gate and clients.channel's
    // schema comment for why is_owner alone isn't the boundary here).
    // shop_id/status/is_owner stay - every other consumer of this
    // response (sync_service.dart, license_service.dart,
    // shop_safety_service.dart) only ever reads those three fields, never
    // the settlement ones, confirmed by inspection before adding this.
    $isBrowser = $client['channel'] === 'browser';
    jsonResponse([
        'success' => true,
        'shop_id' => (int) $client['shop_id'],
        'status' => $client['status'],
        'business_name' => $isBrowser ? null : $client['business_name'],
        'settlement_type' => $isBrowser ? null : $client['settlement_type'],
        'bank_code' => $isBrowser ? null : $client['bank_code'],
        'account_number' => $isBrowser ? null : $client['account_number'],
        'account_name' => $isBrowser ? null : $client['account_name'],
        'subaccount_code' => $isBrowser ? null : $client['subaccount_code'],
        'is_verified' => $isBrowser ? false : (bool) $client['is_verified'],
        // Lets the app show/hide or disable the settlement form up
        // front instead of a non-owner device filling it in and only
        // then hitting save_settlement_details' 403.
        'is_owner' => (bool) $client['is_owner'],
    ]);
}

if ($action === 'list_banks' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    // No legitimate reason a browser client would ever need this list -
    // it only exists to populate save_settlement_details' form, which is
    // already unconditionally blocked for this channel above.
    if ($client['channel'] === 'browser') {
        jsonResponse(['success' => false, 'message' => 'Not available from a browser session.'], 403);
    }

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
        $result = (new PaystackClient())->initializeTransaction($amount, $reference, $email, $currency, $client['subaccount_code'], $metadata, ($body['return_to_app'] ?? false) === true);
    } catch (\Throwable $e) {
        jsonResponse(['status' => false, 'message' => 'Could not reach Paystack: ' . $e->getMessage()], 502);
    }

    if (($result['body']['status'] ?? false) === true) {
        // Paystack has already created a real, chargeable session by
        // this point - the customer must still get $result['body']'s
        // real authorization URL back regardless of what happens here,
        // so a failed INSERT (duplicate reference, or any other error)
        // is logged, not thrown - losing local tracking of a real
        // transaction is bad, but turning it into a 500 that blocks the
        // customer from paying at all is worse. reference is UNIQUE;
        // this is the same "catch, don't crash" shape as leads.email in
        // nexapos_license.
        try {
            $insert = $pdo->prepare('
                INSERT INTO transactions (client_id, reference, amount_minor, currency, subaccount_code)
                VALUES (?, ?, ?, ?, ?)
            ');
            $insert->execute([$client['id'], $reference, $amount, $currency, $client['subaccount_code']]);
        } catch (\Throwable $e) {
            error_log('[nexapos_platform] Could not record transaction (reference=' . $reference . '): ' . $e->getMessage());
        }
    }

    jsonResponse($result['body'], $result['http_code']);
}

if ($action === 'verify_transaction' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    $reference = trim((string) ($_GET['reference'] ?? ''));
    if ($reference === '') {
        jsonResponse(['status' => false, 'message' => 'reference is required.'], 422);
    }

    try {
        $paystack = new PaystackClient();
        $reconciler = new PaymentReconciler($pdo, [$paystack, 'verifyTransaction']);
        $verification = $reconciler->verifyForClient($reference, (int) $client['id']);
    } catch (\Throwable $e) {
        jsonResponse(['status' => false, 'message' => 'Could not reach Paystack: ' . $e->getMessage()], 502);
    }

    if ($verification['outcome'] === 'not_found') {
        jsonResponse(['status' => false, 'message' => 'Transaction not found.'], 404);
    }
    if ($verification['outcome'] === 'mismatch') {
        jsonResponse(['status' => false, 'message' => 'Transaction verification details did not match the original request.'], 409);
    }

    $result = $verification['paystack_result'];
    jsonResponse($result['body'], $result['http_code']);
}

/**
 * IntaSend's sibling of initialize_transaction - deliberately has no
 * subaccount_code-style gate, since collecting into a wallet needs no
 * settlement details (see ensureIntaSendWallet's doc). Every device in
 * every shop can accept an IntaSend payment the moment it's registered.
 */
if ($action === 'intasend_collect' && $method === 'POST') {
    $client = Auth::requireClient($pdo);
    $body = requestBody();
    $amount = (int) ($body['amount'] ?? 0);
    $reference = trim((string) ($body['reference'] ?? ''));
    $phone = trim((string) ($body['phone_number'] ?? ''));
    $name = trim((string) ($body['name'] ?? '')) ?: null;
    $email = trim((string) ($body['email'] ?? '')) ?: null;

    // Same upper bound as initialize_transaction, same reasoning.
    if ($amount < 1 || $amount > 100_000_000 || $reference === '') {
        jsonResponse(['status' => false, 'message' => 'amount and reference are required.'], 422);
    }
    // Kenyan MSISDN in international format (2547xxxxxxxx / 2541xxxxxxxx)
    // - the shape IntaSend's M-Pesa STK push expects. The app is
    // expected to have already normalized a local "07xx"/"+254 7xx"
    // entry before this call; this is a server-side safety net, not the
    // primary UX validation.
    if (!preg_match('/^254\d{9}$/', $phone)) {
        jsonResponse(['status' => false, 'message' => 'Enter a valid M-Pesa number, starting with 254.'], 422);
    }

    try {
        $intasend = new IntaSendClient();
        $walletId = ensureIntaSendWallet($pdo, $client, $intasend);
        $result = $intasend->mpesaStkPush($amount, $phone, $reference, $name, $email, $walletId);
    } catch (\Throwable $e) {
        jsonResponse(['status' => false, 'message' => 'Could not reach IntaSend: ' . $e->getMessage()], 502);
    }

    $invoice = (array) ($result['body']['invoice'] ?? []);
    // Both field names seen across IntaSend's own official SDK example
    // (invoice_id) and their prose docs' sample JSON (id) - see
    // IntaSendClient's class doc. Accepting either is cheap insurance
    // against exactly the kind of drift that already happened once.
    $invoiceId = trim((string) ($invoice['invoice_id'] ?? $invoice['id'] ?? ''));
    if ($result['http_code'] >= 400 || $invoiceId === '') {
        $message = (string) ($result['body']['detail'] ?? $result['body']['message'] ?? 'IntaSend did not accept the request.');
        jsonResponse(['status' => false, 'message' => $message], $result['http_code'] >= 400 ? $result['http_code'] : 502);
    }

    // Same "don't turn a real charge attempt into a 500" reasoning as
    // initialize_transaction's own insert - the STK push has already
    // been sent to the customer's phone by this point.
    try {
        $insert = $pdo->prepare('
            INSERT INTO intasend_transactions (client_id, reference, invoice_id, amount_minor, currency, wallet_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([$client['id'], $reference, $invoiceId, $amount, 'KES', $walletId]);
    } catch (\Throwable $e) {
        error_log('[nexapos_platform] Could not record intasend transaction (reference=' . $reference . '): ' . $e->getMessage());
    }

    jsonResponse(['status' => true, 'data' => ['reference' => $reference, 'invoice_id' => $invoiceId]]);
}

if ($action === 'intasend_status' && $method === 'GET') {
    $client = Auth::requireClient($pdo);
    $reference = trim((string) ($_GET['reference'] ?? ''));
    if ($reference === '') {
        jsonResponse(['status' => false, 'message' => 'reference is required.'], 422);
    }

    try {
        $intasend = new IntaSendClient();
        $reconciler = new IntaSendReconciler($pdo, [$intasend, 'status']);
        $verification = $reconciler->verifyForClient($reference, (int) $client['id']);
    } catch (\Throwable $e) {
        jsonResponse(['status' => false, 'message' => 'Could not reach IntaSend: ' . $e->getMessage()], 502);
    }

    if ($verification['outcome'] === 'not_found') {
        jsonResponse(['status' => false, 'message' => 'Transaction not found.'], 404);
    }

    $response = $verification['response'];
    jsonResponse($response['body'], $response['http_code']);
}

jsonResponse(['success' => false, 'status' => false, 'message' => 'Unknown action.'], 404);
