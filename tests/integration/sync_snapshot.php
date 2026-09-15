<?php
declare(strict_types=1);

require __DIR__ . '/../../app/Core/Database.php';
require __DIR__ . '/../../app/Services/SyncSnapshot.php';

use Platform\Core\Database;
use Platform\Services\SyncSnapshot;

if (!str_contains((string) getenv('DB_NAME'), '_test') || getenv('NEXAPOS_IGNORE_LOCAL_CONFIG') !== '1') {
    throw new RuntimeException('Run only against an explicitly configured test database.');
}
function verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo = Database::connection();
$pdo->exec("INSERT INTO shops(business_name) VALUES ('Snapshot test')");
$shop = (int) $pdo->lastInsertId();
$token = bin2hex(random_bytes(8));
$create = $pdo->prepare("INSERT INTO clients(device_id, device_label, api_key_hash, shop_id) VALUES (?, 'Snapshot test', ?, ?)");
$create->execute(['snapshot-' . $token, hash('sha256', $token), $shop]);
$client = ['id' => (int) $pdo->lastInsertId(), 'shop_id' => $shop];
$insert = $pdo->prepare('INSERT INTO sync_changes(shop_id, table_name, row_id, device_id, local_rev, updated_at, payload) VALUES (?, ?, ?, ?, ?, ?, ?)');
$event = function(string $table, string $row, string $stamp, string $device, string $value) use ($insert, $pdo, $shop): int {
    $insert->execute([$shop, $table, $row, 'relay-device', 1, $stamp,
        json_encode(['id' => $row, 'updatedAt' => $stamp, 'createdByDeviceId' => $device, 'value' => $value])]);
    return (int) $pdo->lastInsertId();
};
$stamp = '2026-09-15T10:00:00.000000Z';
$pdo->beginTransaction();
$event('products', 'winner', $stamp, 'a', 'old');
$winningId = $event('products', 'winner', $stamp, 'z', 'winner');
$event('products', 'winner', $stamp, 'z', 'exact-tie-must-not-win');
$event('products', 'winner', '2025-01-01', 'zz', 'late-old-must-not-win');
$firstMovement = $event('stock_movements', 'movement', $stamp, 'a', 'first');
$event('stock_movements', 'movement', '2099-01-01', 'z', 'must-not-replace');
$firstItem = $event('sale_items', 'item', $stamp, 'a', 'first');
$event('sale_items', 'item', '2099-01-01', 'z', 'must-not-replace');
for ($i = 0; $i < 503; $i++) $event('categories', "category-$i", $stamp, 'a', 'kept');
for ($i = 0; $i < 5000; $i++) $event('products', 'winner', $stamp, 'a', 'duplicate-old');
$pdo->commit();
$snapshot = SyncSnapshot::start($pdo, $client);
verify($snapshot['total'] === 506, 'Only one version per record must be selected.');
verify(SyncSnapshot::start($pdo, $client) === $snapshot, 'Start must be idempotent for a retry.');
$future = $event('categories', 'after-snapshot', $stamp, 'a', 'newer');
verify($future > $snapshot['high_water'], 'New changes must follow the snapshot boundary.');
$after = 0;
$all = [];
do {
    $page = SyncSnapshot::page($pdo, $client, $snapshot['snapshot_id'], $after);
    verify($page !== null && count($page['changes']) <= 500, 'Bounded page is required.');
    $all = array_merge($all, $page['changes']);
    $after = $page['next_cursor'];
} while ($page['has_more']);
verify(count($all) === 506, 'Pages must be complete and stable during writes.');
$ids = array_column($all, 'id');
verify(in_array($winningId, $ids, true), 'LWW must use payload device ID, not relay device or newest ID.');
verify(in_array($firstMovement, $ids, true) && in_array($firstItem, $ids, true), 'Append-only rows retain first event.');
verify(!in_array($future, $ids, true), 'Snapshot must not include later writes.');
verify(SyncSnapshot::page($pdo, ['id' => $client['id'] + 1, 'shop_id' => $shop], $snapshot['snapshot_id'], 0) === null, 'Other devices cannot read the snapshot.');
verify(SyncSnapshot::page($pdo, ['id' => $client['id'], 'shop_id' => $shop + 1], $snapshot['snapshot_id'], 0) === null, 'Other shops cannot read the snapshot.');
$expire = $pdo->prepare('UPDATE sync_snapshots SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = ?');
$expire->execute([$snapshot['snapshot_id']]);
verify(SyncSnapshot::page($pdo, $client, $snapshot['snapshot_id'], 0) === null, 'Expired snapshots must be rejected.');
$replacement = SyncSnapshot::start($pdo, $client);
verify($replacement['snapshot_id'] !== $snapshot['snapshot_id'] && $replacement['total'] === 507, 'Retry after expiry must capture new records.');
echo "Snapshot integration tests passed (5,511 history events reduced to 506 records).\n";
