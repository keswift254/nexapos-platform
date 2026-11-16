<?php

declare(strict_types=1);

namespace Platform\Services;

use PDO;

/**
 * One sale of the shop, with everything a lite till needs to REFUND it: its lines, the refunds already made of it and, line by
 * line, how much is still left to give back - worked out here, from the newest version of each record, by the same rules as the
 * apps (RefundService.refundableSale) so the plain-JavaScript page on an old iPad never has to merge a shop's sync history.
 *
 * A refund is its own sale of type 'refund' with negative amounts (see the app's sales_table.dart), pointing at the sale it
 * gives back (refundOfSaleId) with negative lines pointing at the lines they give back (refundOfItemId). Read-only.
 */
final class LiteSale
{
    /** @return list<array<string,mixed>> the paid, undeleted refunds of one sale, oldest first */
    public static function refundsOf(PDO $pdo, int $shopId, string $saleId): array
    {
        $cursor = self::cursor($pdo, $shopId);
        $found = LiteReport::newest($pdo, $shopId, $cursor, 'sales', "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.refundOfSaleId')) = ?", [$saleId]);
        $refunds = [];
        foreach ($found as $refund) {
            if (($refund['saleType'] ?? '') === 'refund' && ($refund['status'] ?? '') === 'paid') {
                $refunds[] = $refund;
            }
        }
        usort($refunds, static fn (array $a, array $b): int => [(string) ($a['createdAt'] ?? ''), (string) ($a['saleNumber'] ?? '')] <=> [(string) ($b['createdAt'] ?? ''), (string) ($b['saleNumber'] ?? '')]);

        return $refunds;
    }

    /**
     * The sale and what a refund needs to know about it, or null when the shop has no such sale.
     *
     * @return array<string,mixed>|null
     */
    public static function find(PDO $pdo, int $shopId, string $saleId): ?array
    {
        $cursor = self::cursor($pdo, $shopId);
        $sales = LiteReport::newest($pdo, $shopId, $cursor, 'sales', "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [$saleId]);
        $sale = $sales[$saleId] ?? null;
        if ($sale === null) {
            return null;
        }

        $items = LiteReport::newest($pdo, $shopId, $cursor, 'sale_items', "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.saleId')) = ?", [$saleId]);
        $lines = array_values($items);
        usort($lines, static fn (array $a, array $b): int => [(string) ($a['createdAt'] ?? ''), (string) ($a['id'] ?? '')] <=> [(string) ($b['createdAt'] ?? ''), (string) ($b['id'] ?? '')]);

        $refunds = self::refundsOf($pdo, $shopId, $saleId);
        $given = [];
        $subtotal = 0;
        $discount = 0;
        $total = 0;
        $cash = 0;
        foreach ($refunds as $refund) {
            $subtotal -= (int) ($refund['subtotalCents'] ?? 0);
            $discount -= (int) ($refund['discountCents'] ?? 0);
            $total -= (int) ($refund['totalCents'] ?? 0);
            $cash -= (int) ($refund['cashReceivedCents'] ?? 0);
            $refundLines = LiteReport::newest($pdo, $shopId, $cursor, 'sale_items', "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.saleId')) = ?", [(string) $refund['id']]);
            foreach ($refundLines as $refundLine) {
                $of = (string) ($refundLine['refundOfItemId'] ?? '');
                if ($of !== '') {
                    $given[$of] = ($given[$of] ?? 0) - (int) ($refundLine['quantity'] ?? 0);
                }
            }
        }

        $left = [];
        foreach ($lines as $line) {
            $id = (string) $line['id'];
            $refunded = $given[$id] ?? 0;
            $left[] = ['itemId' => $id, 'quantity' => (int) ($line['quantity'] ?? 0), 'refunded' => $refunded, 'remaining' => (int) ($line['quantity'] ?? 0) - $refunded];
        }

        $names = [];
        foreach (LiteReport::newest($pdo, $shopId, $cursor, 'users', "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [(string) ($sale['userId'] ?? '')]) as $id => $user) {
            $names[(string) $id] = (string) ($user['name'] ?? '');
        }

        return [
            'sale' => $sale,
            'cashierName' => $names[(string) ($sale['userId'] ?? '')] ?? 'Unknown',
            'items' => $lines,
            'lines' => $left,
            'refundCount' => count($refunds),
            'refunded' => ['subtotalCents' => $subtotal, 'discountCents' => $discount, 'totalCents' => $total, 'cashCents' => $cash],
        ];
    }

    private static function cursor(PDO $pdo, int $shopId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM sync_changes WHERE shop_id = ?');
        $stmt->execute([$shopId]);

        return (int) $stmt->fetchColumn();
    }
}
