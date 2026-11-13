<?php

declare(strict_types=1);

namespace Platform\Services;

use PDO;

/**
 * The shop's Reports for the old-iPad "lite" till - the same numbers the apps' Reports screen shows (sales, cash and
 * M-Pesa received, expenses, gross profit, net profit / grand total, the list of sales and of expenses), added up
 * across EVERY device of the shop, because every device's sales reach the server.
 *
 * Like LiteCatalog this is worked out here, once, by the app's own rules, so the plain-JavaScript page on an old iPad
 * never has to pull and merge a shop's sync history:
 *
 *  - the newest version of each record wins (later updatedAt; on an exact tie the larger device id), a deleted record
 *    is left out, exactly as in LiteCatalog;
 *  - only PAID sales count (a waiting, cancelled or refused online payment is not a sale), by the sale's own
 *    createdAt, in [fromUtc, toUtc) - the apps compare these ISO texts the same way;
 *  - gross profit is per sale: the sale's total (discount already taken off) minus the cost of its items, summed -
 *    never a flat per-item margin, which would count a discount twice (ReportsDao.grossProfitCentsForRange);
 *  - expenses are by their plain calendar date in [fromDate, toDate);
 *  - cash and M-Pesa received: a cash sale is all cash; a Paystack/M-Pesa sale is M-Pesa, except for a split sale whose
 *    cash part was taken first (ReportSaleRow.cashPortionCents / mpesaPortionCents).
 *
 * The range is chosen by the till in the iPad's own local time (so "today" means the shopkeeper's today); this class
 * only compares. Read-only.
 */
final class LiteReport
{
    /** More rows than this are counted in the totals but not listed (an old iPad cannot draw thousands of rows). */
    public const MAX_LISTED = 500;

    private const MPESA_FAMILY = ['paystack', 'mpesa', 'mpesa_manual', 'intasend'];

    /** @return array<string,mixed> */
    public static function build(PDO $pdo, int $shopId, string $fromUtc, string $toUtc, string $fromDate, string $toDate): array
    {
        [$paid, $itemsBySale, $expenses, $names] = self::load($pdo, $shopId, $fromUtc, $toUtc, $fromDate, $toDate);

        return self::aggregate($paid, $itemsBySale, $expenses, $names, $fromUtc, $toUtc, $fromDate, $toDate);
    }

    /**
     * The dashboard's day-by-day figures: for each consecutive period [bounds[i], bounds[i+1]) (UTC instants, for sales) and
     * [dates[i], dates[i+1]) (plain dates, for expenses) the sales total and count, gross profit, expenses and net profit
     * - the numbers behind "Sales Today", "Net Profit Today" and the last-7-days chart. The whole span is read ONCE.
     *
     * @param list<string> $bounds ascending, at least 2
     * @param list<string> $dates  one per bound
     * @return list<array<string,mixed>>
     */
    public static function days(PDO $pdo, int $shopId, array $bounds, array $dates): array
    {
        $last = count($bounds) - 1;
        [$paid, $itemsBySale, $expenses, $names] = self::load($pdo, $shopId, $bounds[0], $bounds[$last], $dates[0], $dates[$last]);

        return self::buckets($paid, $itemsBySale, $expenses, $names, $bounds, $dates);
    }

    /**
     * The per-period figures from records already resolved to their newest versions (pure, so it can be tested exactly).
     *
     * @param array<string,array<string,mixed>> $paidSales
     * @param array<string,list<array<string,mixed>>> $itemsBySale
     * @param array<string,array<string,mixed>> $expenses
     * @param array<string,string> $userNames
     * @param list<string> $bounds
     * @param list<string> $dates
     * @return list<array<string,mixed>>
     */
    public static function buckets(array $paidSales, array $itemsBySale, array $expenses, array $userNames, array $bounds, array $dates): array
    {
        $out = [];
        for ($i = 0; $i + 1 < count($bounds); $i++) {
            $part = self::aggregate($paidSales, $itemsBySale, $expenses, $userNames, $bounds[$i], $bounds[$i + 1], $dates[$i], $dates[$i + 1]);
            $out[] = [
                'fromUtc' => $bounds[$i],
                'toUtc' => $bounds[$i + 1],
                'fromDate' => $dates[$i],
                'toDate' => $dates[$i + 1],
                'salesTotalCents' => $part['salesTotalCents'],
                'transactionCount' => $part['transactionCount'],
                'grossProfitCents' => $part['grossProfitCents'],
                'expensesTotalCents' => $part['expensesTotalCents'],
                'netProfitCents' => $part['netProfitCents'],
            ];
        }
        return $out;
    }

    /**
     * The newest versions of what a period needs: its paid sales, their items, its expenses and the names of the users.
     *
     * @return array{0:array<string,array<string,mixed>>,1:array<string,list<array<string,mixed>>>,2:array<string,array<string,mixed>>,3:array<string,string>}
     */
    private static function load(PDO $pdo, int $shopId, string $fromUtc, string $toUtc, string $fromDate, string $toDate): array
    {
        $cursorStmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM sync_changes WHERE shop_id = ?');
        $cursorStmt->execute([$shopId]);
        $cursor = (int) $cursorStmt->fetchColumn();

        $sales = self::newest($pdo, $shopId, $cursor, 'sales',
            "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.createdAt')) >= ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.createdAt')) < ?",
            [$fromUtc, $toUtc]);

        $paid = [];
        foreach ($sales as $id => $sale) {
            if (($sale['status'] ?? '') === 'paid') {
                $paid[(string) $id] = $sale;
            }
        }

        // The items of those sales (for their cost and their names), fetched in bounded batches.
        $itemsBySale = [];
        $saleIds = array_keys($paid);
        foreach (array_chunk($saleIds, 400) as $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $items = self::newest($pdo, $shopId, $cursor, 'sale_items',
                "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.saleId')) IN ($marks)", $batch);
            foreach ($items as $item) {
                $itemsBySale[(string) ($item['saleId'] ?? '')][] = $item;
            }
        }

        $expenses = self::newest($pdo, $shopId, $cursor, 'expenses',
            "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.expenseDate')) >= ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.expenseDate')) < ?",
            [$fromDate, $toDate]);

        $names = [];
        foreach (self::newest($pdo, $shopId, $cursor, 'users', '', []) as $id => $user) {
            $names[(string) $id] = (string) ($user['name'] ?? '');
        }

        return [$paid, $itemsBySale, $expenses, $names];
    }

    /**
     * The numbers and lists, from records already resolved to their newest versions. Pure (no database), so the
     * rules above can be tested exactly.
     *
     * @param array<string,array<string,mixed>> $paidSales   sale id => sale, paid ones in range
     * @param array<string,list<array<string,mixed>>> $itemsBySale sale id => its (non-deleted) items
     * @param array<string,array<string,mixed>> $expenses    expense id => expense, in range
     * @param array<string,string> $userNames                user id => name
     * @return array<string,mixed>
     */
    public static function aggregate(array $paidSales, array $itemsBySale, array $expenses, array $userNames,
        string $fromUtc, string $toUtc, string $fromDate, string $toDate): array
    {
        $salesTotal = 0;
        $grossProfit = 0;
        $cash = 0;
        $mpesa = 0;
        $rows = [];

        foreach ($paidSales as $id => $sale) {
            $createdAt = (string) ($sale['createdAt'] ?? '');
            if (strcmp($createdAt, $fromUtc) < 0 || strcmp($createdAt, $toUtc) >= 0) {
                continue;
            }
            if (($sale['status'] ?? '') !== 'paid') {
                continue;
            }
            $total = (int) ($sale['totalCents'] ?? 0);
            $method = (string) ($sale['paymentMethod'] ?? '');
            $cashReceived = isset($sale['cashReceivedCents']) ? (int) $sale['cashReceivedCents'] : null;

            $cost = 0;
            $names = [];
            $items = $itemsBySale[(string) $id] ?? [];
            // By when each was rung up; items rung up at the very same instant stay in the order they arrived (the sort is stable).
            usort($items, static fn (array $a, array $b): int => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')));
            foreach ($items as $item) {
                $quantity = (int) ($item['quantity'] ?? 0);
                $cost += $quantity * (int) ($item['costPriceCents'] ?? 0);
                $names[] = (string) ($item['itemName'] ?? '') . ' x' . $quantity;
            }

            $salesTotal += $total;
            $grossProfit += $total - $cost;
            if ($method === 'cash') {
                $cash += $total;
            } elseif (in_array($method, self::MPESA_FAMILY, true)) {
                $cash += $cashReceived ?? 0;
                $mpesa += $total - ($cashReceived ?? 0);
            }

            $rows[] = [
                'id' => (string) $id,
                'saleNumber' => (string) ($sale['saleNumber'] ?? ''),
                'cashierName' => $userNames[(string) ($sale['userId'] ?? '')] ?? 'Unknown',
                'saleType' => (string) ($sale['saleType'] ?? ''),
                'paymentMethod' => $method,
                'status' => 'paid',
                'totalCents' => $total,
                'createdAt' => $createdAt,
                'itemNames' => $names === [] ? null : implode(', ', $names),
            ];
        }
        $transactions = count($rows);
        // Newest first, as in the apps; a tie is broken by the sale number so the order is always the same.
        usort($rows, static fn (array $a, array $b): int => [$b['createdAt'], $b['saleNumber']] <=> [$a['createdAt'], $a['saleNumber']]);

        $expensesTotal = 0;
        $expenseRows = [];
        foreach ($expenses as $id => $expense) {
            $date = (string) ($expense['expenseDate'] ?? '');
            if (strcmp($date, $fromDate) < 0 || strcmp($date, $toDate) >= 0) {
                continue;
            }
            $amount = (int) ($expense['amountCents'] ?? 0);
            $expensesTotal += $amount;
            $userId = isset($expense['userId']) ? (string) $expense['userId'] : '';
            $expenseRows[] = [
                'id' => (string) $id,
                'title' => (string) ($expense['title'] ?? ''),
                'note' => isset($expense['note']) && $expense['note'] !== '' ? (string) $expense['note'] : null,
                'amountCents' => $amount,
                'createdAt' => (string) ($expense['createdAt'] ?? ''),
                'enteredByName' => ($userId !== '' && ($userNames[$userId] ?? '') !== '') ? $userNames[$userId] : 'System Admin',
                'expenseDate' => $date,
            ];
        }
        // Oldest first, as in the apps (expense date, then when it was entered, then id).
        usort($expenseRows, static fn (array $a, array $b): int => [$a['expenseDate'], $a['createdAt'], $a['id']] <=> [$b['expenseDate'], $b['createdAt'], $b['id']]);
        $expenseCount = count($expenseRows);

        return [
            'range' => ['fromUtc' => $fromUtc, 'toUtc' => $toUtc, 'fromDate' => $fromDate, 'toDate' => $toDate],
            'salesTotalCents' => $salesTotal,
            'transactionCount' => $transactions,
            'cashReceivedCents' => $cash,
            'mpesaCents' => $mpesa,
            'expensesTotalCents' => $expensesTotal,
            'expenseCount' => $expenseCount,
            'grossProfitCents' => $grossProfit,
            'netProfitCents' => $grossProfit - $expensesTotal,
            'grandTotalCents' => $salesTotal - $expensesTotal,
            'sales' => array_slice($rows, 0, self::MAX_LISTED),
            'salesTruncated' => $transactions > self::MAX_LISTED,
            'expenses' => array_slice($expenseRows, 0, self::MAX_LISTED),
            'expensesTruncated' => $expenseCount > self::MAX_LISTED,
        ];
    }

    /**
     * The newest version of each record of one table that matches [$where] (a SQL fragment starting with AND, using
     * only ? marks bound from [$params]), by the app's last-write-wins rule; deleted records are left out.
     *
     * @param list<string> $params
     * @return array<string,array<string,mixed>> row id => payload
     */
    private static function newest(PDO $pdo, int $shopId, int $cursor, string $table, string $where, array $params): array
    {
        $stmt = $pdo->prepare(
            "SELECT row_id, device_id, updated_at, payload FROM sync_changes
             WHERE shop_id = ? AND id <= ? AND table_name = ? $where ORDER BY id"
        );
        $stmt->execute(array_merge([$shopId, $cursor, $table], $params));

        $best = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = (string) $row['row_id'];
            $current = $best[$key] ?? null;
            if ($current === null
                || strcmp((string) $row['updated_at'], $current['updated_at']) > 0
                || ($row['updated_at'] === $current['updated_at'] && strcmp((string) $row['device_id'], $current['device_id']) > 0)) {
                $best[$key] = ['updated_at' => (string) $row['updated_at'], 'device_id' => (string) $row['device_id'], 'payload' => (string) $row['payload']];
            }
        }

        $out = [];
        foreach ($best as $key => $version) {
            $payload = json_decode($version['payload'], true);
            if (!is_array($payload) || ($payload['deletedAt'] ?? null) !== null) {
                continue;
            }
            $out[$key] = $payload;
        }
        return $out;
    }
}
