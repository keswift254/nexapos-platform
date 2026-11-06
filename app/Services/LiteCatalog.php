<?php

declare(strict_types=1);

namespace Platform\Services;

use PDO;

/**
 * What the old-iPad "lite" till needs to know about its shop, worked out on the server: the CURRENT
 * products, categories, cashiers and business details, with each product's stock already added up.
 *
 * A full app keeps its own database and merges every sync record itself. The lite till is a small
 * plain-JavaScript page that must not: re-implementing that merge (and pulling a shop's whole
 * history of sales to do it) on an old iPad would be slow and a place for bugs. So the rules the app
 * uses are applied here, once, in tested code:
 *
 *  - for each record the newest version wins - the later updatedAt, and on an exact tie the larger
 *    device id (SyncService._applyWithLastWriteWins), never "the one that arrived last";
 *  - a deleted record is not listed;
 *  - a product's stock is the sum of its stock movements (SyncService._recomputeStockQty); the
 *    stockQty inside a product record is not synced and is never used.
 *
 * Read-only. Everything is bounded by one cursor (the newest sync id at the start), returned so a later
 * refresh can ask what changed since.
 */
final class LiteCatalog
{
    private const TABLES = ['products', 'categories', 'users', 'roles', 'business_settings'];

    /** @return array<string,mixed> */
    public static function build(PDO $pdo, int $shopId): array
    {
        $cursorStmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM sync_changes WHERE shop_id = ?');
        $cursorStmt->execute([$shopId]);
        $cursor = (int) $cursorStmt->fetchColumn();

        // The newest version of every master-data record (rows are read one at a time: a shop can hold
        // many revisions of many products).
        $newest = [];
        $rows = $pdo->prepare(
            'SELECT table_name, row_id, device_id, updated_at, payload FROM sync_changes
             WHERE shop_id = ? AND id <= ? AND table_name IN (' . implode(',', array_fill(0, count(self::TABLES), '?')) . ')
             ORDER BY id'
        );
        $rows->execute(array_merge([$shopId, $cursor], self::TABLES));
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['table_name'] . '/' . $row['row_id'];
            $current = $newest[$key] ?? null;
            if ($current === null
                || strcmp($row['updated_at'], $current['updated_at']) > 0
                || ($row['updated_at'] === $current['updated_at'] && strcmp($row['device_id'], $current['device_id']) > 0)) {
                $newest[$key] = ['updated_at' => $row['updated_at'], 'device_id' => $row['device_id'], 'payload' => $row['payload']];
            }
        }

        $byTable = array_fill_keys(self::TABLES, []);
        foreach ($newest as $key => $version) {
            $payload = json_decode((string) $version['payload'], true);
            if (!is_array($payload) || ($payload['deletedAt'] ?? null) !== null) {
                continue;
            }
            $byTable[explode('/', $key, 2)[0]][] = $payload;
        }

        // Stock: the sum of every stock movement of the product.
        $stock = [];
        $sums = $pdo->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.productId')) AS product_id,
                    SUM(CAST(JSON_EXTRACT(payload, '$.quantity') AS SIGNED)) AS quantity
             FROM sync_changes
             WHERE shop_id = ? AND id <= ? AND table_name = 'stock_movements'
               AND (JSON_EXTRACT(payload, '$.deletedAt') IS NULL OR JSON_TYPE(JSON_EXTRACT(payload, '$.deletedAt')) = 'NULL')
             GROUP BY product_id"
        );
        $sums->execute([$shopId, $cursor]);
        foreach ($sums->fetchAll(PDO::FETCH_ASSOC) as $sum) {
            $stock[(string) $sum['product_id']] = (int) $sum['quantity'];
        }

        $products = [];
        foreach ($byTable['products'] as $p) {
            $products[] = [
                'id' => (string) $p['id'],
                'sku' => (string) ($p['sku'] ?? ''),
                'name' => (string) ($p['name'] ?? ''),
                'barcode' => isset($p['barcode']) ? (string) $p['barcode'] : null,
                'categoryId' => (string) ($p['categoryId'] ?? ''),
                'retailPriceCents' => (int) ($p['retailPriceCents'] ?? 0),
                'wholesalePriceCents' => (int) ($p['wholesalePriceCents'] ?? 0),
                'costPriceCents' => (int) ($p['costPriceCents'] ?? 0),
                'reorderLevel' => (int) ($p['reorderLevel'] ?? 0),
                'status' => (string) ($p['status'] ?? 'active'),
                'stockQty' => $stock[(string) $p['id']] ?? 0,
            ];
        }
        usort($products, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $categories = [];
        foreach ($byTable['categories'] as $c) {
            $categories[] = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? ''), 'status' => (string) ($c['status'] ?? 'active')];
        }
        usort($categories, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $users = [];
        foreach ($byTable['users'] as $u) {
            $users[] = [
                'id' => (string) $u['id'],
                'roleId' => (string) ($u['roleId'] ?? ''),
                'name' => (string) ($u['name'] ?? ''),
                'username' => (string) ($u['username'] ?? ''),
                'passwordHash' => (string) ($u['passwordHash'] ?? ''),
                'status' => (string) ($u['status'] ?? 'active'),
            ];
        }
        usort($users, static fn (array $a, array $b): int => strcasecmp($a['username'], $b['username']));

        $roles = [];
        foreach ($byTable['roles'] as $r) {
            $roles[] = ['id' => (string) $r['id'], 'name' => (string) ($r['name'] ?? '')];
        }

        $business = null;
        foreach ($byTable['business_settings'] as $b) {
            $business = [
                'businessName' => (string) ($b['businessName'] ?? ''),
                'address' => isset($b['address']) ? (string) $b['address'] : null,
                'phone' => isset($b['phone']) ? (string) $b['phone'] : null,
                'receiptFooter' => isset($b['receiptFooter']) ? (string) $b['receiptFooter'] : null,
                'currency' => (string) ($b['currency'] ?? 'KES'),
                'paperWidthMm' => (int) ($b['paperWidthMm'] ?? 58),
            ];
        }

        return [
            'cursor' => $cursor,
            'business' => $business,
            'categories' => $categories,
            'products' => $products,
            'users' => $users,
            'roles' => $roles,
        ];
    }
}
