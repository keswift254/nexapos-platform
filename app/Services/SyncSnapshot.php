<?php

declare(strict_types=1);

namespace Platform\Services;

use PDO;

/** Immutable change IDs keep pagination stable while the shop keeps trading. */
final class SyncSnapshot
{
    public static function start(PDO $pdo, array $client): array
    {
        $pdo->beginTransaction();
        try {
            // Shared with push_changes: no lower uncommitted change ID can
            // appear after the high-water mark has been captured.
            $lock = $pdo->prepare('SELECT id FROM shops WHERE id = ? FOR UPDATE');
            $lock->execute([$client['shop_id']]);
            $existing = $pdo->prepare('SELECT * FROM sync_snapshots WHERE client_id = ? AND shop_id = ? AND expires_at > UTC_TIMESTAMP() ORDER BY expires_at DESC LIMIT 1');
            $existing->execute([$client['id'], $client['shop_id']]);
            if ($snapshot = $existing->fetch(PDO::FETCH_ASSOC)) {
                $pdo->commit();
                return self::metadata($snapshot);
            }
            $cleanup = $pdo->prepare('DELETE FROM sync_snapshots WHERE client_id = ?');
            $cleanup->execute([$client['id']]);
            $high = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM sync_changes WHERE shop_id = ?');
            $high->execute([$client['shop_id']]);
            $highWater = (int) $high->fetchColumn();
            $id = bin2hex(random_bytes(16));
            $create = $pdo->prepare('INSERT INTO sync_snapshots(id, client_id, shop_id, high_water, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))');
            $create->execute([$id, $client['id'], $client['shop_id'], $highWater]);
            // Match the app exactly: append-only rows take their first event;
            // mutable rows use bytewise (updatedAt, createdByDeviceId), with
            // the first event winning an exact tie. Never use newest ID alone.
            $select = $pdo->prepare("INSERT INTO sync_snapshot_rows(snapshot_id, change_id)
                SELECT ?, id FROM (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY BINARY table_name, BINARY row_id
                        ORDER BY
                            CASE WHEN table_name IN ('sale_items','stock_movements') THEN id ELSE NULL END ASC,
                            CASE WHEN table_name NOT IN ('sale_items','stock_movements') THEN BINARY updated_at ELSE NULL END DESC,
                            CASE WHEN table_name NOT IN ('sale_items','stock_movements') THEN BINARY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.createdByDeviceId')), '') ELSE NULL END DESC,
                            id ASC
                    ) AS winner
                    FROM sync_changes WHERE shop_id = ? AND id <= ?
                ) ranked WHERE winner = 1");
            $select->execute([$id, $client['shop_id'], $highWater]);
            $count = $select->rowCount();
            $update = $pdo->prepare('UPDATE sync_snapshots SET row_count = ? WHERE id = ?');
            $update->execute([$count, $id]);
            $pdo->commit();
            return ['snapshot_id' => $id, 'high_water' => $highWater, 'total' => $count];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function page(PDO $pdo, array $client, string $id, int $after): ?array
    {
        $lookup = $pdo->prepare('SELECT * FROM sync_snapshots WHERE id = ? AND client_id = ? AND shop_id = ? AND expires_at > UTC_TIMESTAMP()');
        $lookup->execute([$id, $client['id'], $client['shop_id']]);
        $snapshot = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot) return null;
        $query = $pdo->prepare('SELECT c.id, c.table_name, c.row_id, c.payload
            FROM sync_snapshot_rows r JOIN sync_changes c ON c.id = r.change_id
            WHERE r.snapshot_id = ? AND r.change_id > ? ORDER BY r.change_id LIMIT 501');
        $query->execute([$id, $after]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 500;
        $rows = array_slice($rows, 0, 500);
        $changes = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'table_name' => $row['table_name'],
            'row_id' => $row['row_id'], 'payload' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
        ], $rows);
        return self::metadata($snapshot) + [
            'changes' => $changes, 'next_cursor' => $changes ? end($changes)['id'] : $after, 'has_more' => $more,
        ];
    }

    private static function metadata(array $row): array
    {
        return ['snapshot_id' => $row['id'], 'high_water' => (int) $row['high_water'], 'total' => (int) $row['row_count']];
    }
}
