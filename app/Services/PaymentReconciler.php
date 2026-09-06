<?php

namespace Platform\Services;

use Closure;
use PDO;
use RuntimeException;

final class PaymentReconciler
{
    private PDO $pdo;
    private Closure $verifyTransaction;

    public function __construct(PDO $pdo, callable $verifyTransaction)
    {
        $this->pdo = $pdo;
        $this->verifyTransaction = Closure::fromCallable($verifyTransaction);
    }

    public static function hasValidSignature(string $rawPayload, string $signature, string $secret): bool
    {
        $signature = strtolower(trim($signature));
        $secret = trim($secret);
        return $secret !== ''
            && preg_match('/^[a-f0-9]{128}$/', $signature) === 1
            && hash_equals(hash_hmac('sha512', $rawPayload, $secret), $signature);
    }

    public function verifyForClient(string $reference, int $clientId): array
    {
        $transaction = $this->findTransaction($reference, $clientId);
        if ($transaction === null) {
            return ['outcome' => 'not_found', 'paystack_result' => null];
        }

        $result = ($this->verifyTransaction)($reference);
        return [
            'outcome' => $this->reconcile($transaction, $result),
            'paystack_result' => $result,
        ];
    }

    public function handleWebhook(array $event, string $rawPayload): array
    {
        $eventType = trim((string) ($event['event'] ?? ''));
        if ($eventType !== 'charge.success') {
            return ['outcome' => 'ignored'];
        }

        $data = $event['data'] ?? null;
        $reference = is_array($data) ? trim((string) ($data['reference'] ?? '')) : '';
        if ($reference === '') {
            throw new RuntimeException('Paystack webhook has no transaction reference.');
        }

        $providerId = is_array($data) ? trim((string) ($data['id'] ?? '')) : '';
        $eventKey = hash('sha256', $eventType . '|' . $providerId . '|' . $reference);
        $insert = $this->pdo->prepare('INSERT IGNORE INTO paystack_webhook_events
            (event_key, event_type, reference, payload_sha256)
            VALUES (?, ?, ?, ?)');
        $insert->execute([$eventKey, $eventType, $reference, hash('sha256', $rawPayload)]);

        $claim = $this->pdo->prepare("UPDATE paystack_webhook_events
            SET attempts = attempts + IF(status = 'received', 0, 1),
                status = 'processing',
                last_error = NULL
            WHERE event_key = ?
              AND (status IN ('received', 'failed')
                   OR (status = 'processing' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)))");
        $claim->execute([$eventKey]);
        if ($claim->rowCount() !== 1) {
            return ['outcome' => 'duplicate'];
        }

        try {
            $transaction = $this->findTransaction($reference, null);
            if ($transaction === null) {
                throw new RuntimeException('Webhook transaction is not recorded locally yet.');
            }

            $result = ($this->verifyTransaction)($reference);
            $outcome = $this->reconcile($transaction, $result);
            if ($outcome === 'pending') {
                throw new RuntimeException('Paystack verification is not final yet.');
            }

            $this->markEvent($eventKey, 'processed', $outcome === 'mismatch' ? 'Payment details did not match.' : null);
            return ['outcome' => $outcome];
        } catch (\Throwable $e) {
            $this->markEvent($eventKey, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function findTransaction(string $reference, ?int $clientId): ?array
    {
        if ($clientId === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM transactions WHERE reference = ?');
            $stmt->execute([$reference]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM transactions WHERE reference = ? AND client_id = ?');
            $stmt->execute([$reference, $clientId]);
        }
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($transaction) ? $transaction : null;
    }

    private function reconcile(array $transaction, array $result): string
    {
        $data = $result['body']['data'] ?? [];
        $paystackStatus = is_array($data) ? strtolower((string) ($data['status'] ?? '')) : '';
        if ($paystackStatus === 'success') {
            $verifiedReference = trim((string) ($data['reference'] ?? $transaction['reference']));
            $verifiedAmount = (int) ($data['amount'] ?? 0);
            $verifiedCurrency = strtoupper((string) ($data['currency'] ?? ''));
            if ($verifiedReference !== (string) $transaction['reference']
                || $verifiedAmount !== (int) $transaction['amount_minor']
                || $verifiedCurrency !== strtoupper((string) $transaction['currency'])) {
                $this->updateStatus((int) $transaction['id'], 'verified_failed');
                error_log('[nexapos_platform] Paystack verification mismatch for reference=' . $transaction['reference']);
                return 'mismatch';
            }
            $this->updateStatus((int) $transaction['id'], 'verified_success');
            return 'success';
        }

        if (in_array($paystackStatus, ['failed', 'abandoned', 'reversed'], true)) {
            $this->updateStatus((int) $transaction['id'], 'verified_failed');
            return 'failed';
        }
        return 'pending';
    }

    private function updateStatus(int $transactionId, string $status): void
    {
        $update = $this->pdo->prepare('UPDATE transactions SET status = ?, verified_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([$status, $transactionId]);
    }

    private function markEvent(string $eventKey, string $status, ?string $error): void
    {
        $message = $error === null ? null : substr($error, 0, 500);
        $processedAt = $status === 'processed' ? 'UTC_TIMESTAMP()' : 'NULL';
        $stmt = $this->pdo->prepare("UPDATE paystack_webhook_events
            SET status = ?, last_error = ?, processed_at = $processedAt
            WHERE event_key = ?");
        $stmt->execute([$status, $message, $eventKey]);
    }
}
