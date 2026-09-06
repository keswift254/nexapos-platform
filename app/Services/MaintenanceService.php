<?php

namespace Platform\Services;

use PDO;

final class MaintenanceService
{
    public function __construct(private PDO $pdo, private array $config)
    {
    }

    public function run(): array
    {
        $batchSize = max(100, min(10_000, (int) ($this->config['maintenance_batch_size'] ?? 1000)));
        $maxBatches = max(1, min(100, (int) ($this->config['maintenance_max_batches'] ?? 10)));

        return [
            'sync_changes' => $this->compactSyncChanges(
                max(7, (int) ($this->config['sync_history_retention_days'] ?? 90)),
                $batchSize,
                $maxBatches
            ),
            'join_attempts' => $this->deleteOlderThan(
                'join_attempts',
                'attempted_at',
                max(1, (int) ($this->config['join_attempt_retention_days'] ?? 7)),
                $batchSize,
                $maxBatches
            ),
            'shop_invites' => $this->deleteOlderThan(
                'shop_invites',
                'expires_at',
                max(1, (int) ($this->config['invite_retention_days'] ?? 30)),
                $batchSize,
                $maxBatches
            ),
        ];
    }

    private function compactSyncChanges(int $retentionDays, int $batchSize, int $maxBatches): int
    {
        $deleted = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            // Keep the newest event for every logical row forever. This
            // makes compaction safe for new and long-offline devices:
            // replaying the retained newest rows reconstructs current state.
            $sql = "DELETE FROM sync_changes WHERE id IN (
                SELECT id FROM (
                    SELECT DISTINCT older.id
                    FROM sync_changes older
                    INNER JOIN sync_changes newer
                        ON newer.shop_id = older.shop_id
                       AND newer.table_name = older.table_name
                       AND newer.row_id = older.row_id
                       AND newer.id > older.id
                    WHERE older.received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $retentionDays DAY)
                    ORDER BY older.id
                    LIMIT $batchSize
                ) stale
            )";
            $count = $this->pdo->exec($sql);
            $count = $count === false ? 0 : $count;
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        return $deleted;
    }

    private function deleteOlderThan(
        string $table,
        string $column,
        int $retentionDays,
        int $batchSize,
        int $maxBatches
    ): int {
        $deleted = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $count = $this->pdo->exec(
                "DELETE FROM $table WHERE $column < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $retentionDays DAY) LIMIT $batchSize"
            );
            $count = $count === false ? 0 : $count;
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        return $deleted;
    }
}
