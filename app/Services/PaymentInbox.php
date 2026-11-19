<?php

declare(strict_types=1);

namespace Platform\Services;

use PDO;
use PDOException;

/**
 * "Manual Till/Paybill": a shop's payment inbox.
 *
 * The customer pays the shop's OWN M-Pesa till or paybill - the money never passes through NexaPOS. Safaricom then sends
 * its "payment received" SMS to the shop's phone, and that phone passes the message on to this inbox (an Android phone
 * through the NexaPOS app, an iPhone through a Shortcuts automation). Any till of the shop - app, web or lite - can then
 * check the code a customer shows against what really arrived, and a code can be used on ONE sale only.
 *
 *  - A SOURCE is a phone allowed to pass messages on. It holds a long random key (only the key's hash is stored). Adding
 *    or removing one needs the password of an administrator of the shop, checked HERE against the shop's own user list:
 *    whoever holds a key could pass on a made-up message, so a cashier must not be able to get one.
 *  - INGEST keeps only payments RECEIVED (see MpesaSms); a balance or money the owner sent is dropped unread.
 *  - CLAIM ties a code to a sale. With no message for the code it can still be claimed (a code typed by the cashier,
 *    "not confirmed") unless the till asks for the message to be there; the message confirms it whenever it arrives.
 */
final class PaymentInbox
{
    public const ADMIN_ROLE_ID = '00000000-0000-4000-8000-000000000001';
    /** Phones a shop may have passing messages on at one time. */
    public const MAX_SOURCES = 5;
    /** Messages one phone may pass on in an hour (every message counts, payment or not). */
    public const HOURLY_LIMIT = 600;
    private const LOCK_AFTER_FAILURES = 5;
    private const LOCK_MINUTES = 10;

    // ------------------------------------------------------------------------------------------------------------
    // Who may add or remove a phone

    /**
     * The shop's administrator [userId] if [password] is theirs. Refuses (and counts a wrong password) otherwise.
     *
     * @return array{id:string,username:string,name:string}
     */
    public static function requireAdmin(PDO $pdo, int $shopId, string $userId, string $password): array
    {
        $recent = $pdo->prepare('SELECT COUNT(*) FROM payment_inbox_auth_failures WHERE shop_id = ? AND failed_at > UTC_TIMESTAMP() - INTERVAL ' . self::LOCK_MINUTES . ' MINUTE');
        $recent->execute([$shopId]);
        if ((int) $recent->fetchColumn() >= self::LOCK_AFTER_FAILURES) {
            throw new PaymentInboxRefusal(429, 'locked', 'Too many wrong passwords. Wait ' . self::LOCK_MINUTES . ' minutes and try again.');
        }

        $user = self::currentUser($pdo, $shopId, $userId);
        if ($user === null || ($user['status'] ?? 'active') !== 'active') {
            throw new PaymentInboxRefusal(403, 'not_admin', 'Only an administrator of the shop can do this.');
        }
        if (($user['roleId'] ?? '') !== self::ADMIN_ROLE_ID) {
            throw new PaymentInboxRefusal(403, 'not_admin', 'Only an administrator of the shop can do this.');
        }
        $hash = (string) ($user['passwordHash'] ?? '');
        // PHP reads "$2b$" hashes the same as its own "$2y$" ones; older builds do not know the "$2b$" name.
        if (strncmp($hash, '$2b$', 4) === 0) {
            $hash = '$2y$' . substr($hash, 4);
        }
        if ($password === '' || $hash === '' || !password_verify($password, $hash)) {
            $pdo->prepare('INSERT INTO payment_inbox_auth_failures (shop_id, failed_at) VALUES (?, UTC_TIMESTAMP())')->execute([$shopId]);
            throw new PaymentInboxRefusal(403, 'wrong_password', 'That password is not right.');
        }
        $pdo->prepare('DELETE FROM payment_inbox_auth_failures WHERE shop_id = ?')->execute([$shopId]);
        return ['id' => (string) $user['id'], 'username' => (string) ($user['username'] ?? ''), 'name' => (string) ($user['name'] ?? '')];
    }

    /** The newest version of one of the shop's users (as the shop's devices see it), or null when there is none or it is deleted. */
    private static function currentUser(PDO $pdo, int $shopId, string $userId): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId) !== 1) {
            return null;
        }
        $rows = $pdo->prepare("SELECT device_id, updated_at, payload FROM sync_changes WHERE shop_id = ? AND table_name = 'users' AND row_id = ? ORDER BY id");
        $rows->execute([$shopId, $userId]);
        $newest = null;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            // The same "newest wins" rule the devices and LiteCatalog use: later updated_at, then the higher device id.
            if ($newest === null
                || strcmp($row['updated_at'], $newest['updated_at']) > 0
                || ($row['updated_at'] === $newest['updated_at'] && strcmp($row['device_id'], $newest['device_id']) > 0)) {
                $newest = $row;
            }
        }
        if ($newest === null) {
            return null;
        }
        $payload = json_decode((string) $newest['payload'], true);
        if (!is_array($payload) || ($payload['deletedAt'] ?? null) !== null) {
            return null;
        }
        return $payload;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Phones that pass messages on

    /**
     * Adds a phone and returns its key - the ONLY time the key is ever given out.
     *
     * @return array{key:string,source:array<string,mixed>}
     */
    public static function addSource(PDO $pdo, int $shopId, int $clientId, string $label, string $byUser): array
    {
        $label = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $label)), 0, 60);
        if ($label === '') {
            $label = 'Phone';
        }
        $active = $pdo->prepare('SELECT COUNT(*) FROM payment_inbox_sources WHERE shop_id = ? AND revoked_at IS NULL');
        $active->execute([$shopId]);
        if ((int) $active->fetchColumn() >= self::MAX_SOURCES) {
            throw new PaymentInboxRefusal(409, 'too_many', 'This shop already has ' . self::MAX_SOURCES . ' phones set up. Remove one first.');
        }
        $key = 'npk_' . bin2hex(random_bytes(24));
        $pdo->prepare(
            'INSERT INTO payment_inbox_sources (shop_id, token_hash, label, created_by_client_id, created_by_user, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$shopId, hash('sha256', $key), $label, $clientId, mb_substr($byUser, 0, 80)]);
        $id = (int) $pdo->lastInsertId();
        foreach (self::sources($pdo, $shopId) as $source) {
            if ($source['id'] === $id) {
                return ['key' => $key, 'source' => $source];
            }
        }
        throw new \RuntimeException('The new phone could not be read back.');
    }

    /** The shop's phones that are set up now (never their keys). @return list<array<string,mixed>> */
    public static function sources(PDO $pdo, int $shopId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, label, created_by_user, created_at, last_used_at FROM payment_inbox_sources
             WHERE shop_id = ? AND revoked_at IS NULL ORDER BY id'
        );
        $stmt->execute([$shopId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'added_by' => (string) ($row['created_by_user'] ?? ''),
                'added_at' => self::iso($row['created_at']),
                'last_message_at' => self::iso($row['last_used_at']),
            ];
        }
        return $out;
    }

    /** Stops a phone's key working. False when the shop has no such phone. */
    public static function removeSource(PDO $pdo, int $shopId, int $sourceId): bool
    {
        $stmt = $pdo->prepare('UPDATE payment_inbox_sources SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND shop_id = ? AND revoked_at IS NULL');
        $stmt->execute([$sourceId, $shopId]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------------------------------------------------
    // A phone passing a message on

    /**
     * @return array{recorded:bool,reason:string}  reason: recorded | already_have_it | not_a_payment
     */
    public static function ingest(PDO $pdo, string $key, string $text): array
    {
        if (preg_match('/^npk_[a-f0-9]{48}$/', $key) !== 1) {
            throw new PaymentInboxRefusal(401, 'bad_key', 'This phone is not set up for a shop (the key is not right).');
        }
        $found = $pdo->prepare('SELECT id, shop_id FROM payment_inbox_sources WHERE token_hash = ? AND revoked_at IS NULL');
        $found->execute([hash('sha256', $key)]);
        $source = $found->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            throw new PaymentInboxRefusal(401, 'bad_key', 'This phone is not set up for a shop (the key is not right).');
        }
        $sourceId = (int) $source['id'];

        // Count this message in the phone's current hour. window_count is assigned BEFORE window_started_at on purpose:
        // MySQL applies the assignments left to right, and the count must be decided from the OLD window start.
        $pdo->prepare(
            'UPDATE payment_inbox_sources SET
                window_count = IF(window_started_at IS NULL OR window_started_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR, 1, window_count + 1),
                window_started_at = IF(window_started_at IS NULL OR window_started_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR, UTC_TIMESTAMP(), window_started_at),
                last_used_at = UTC_TIMESTAMP()
             WHERE id = ?'
        )->execute([$sourceId]);
        $count = $pdo->prepare('SELECT window_count FROM payment_inbox_sources WHERE id = ?');
        $count->execute([$sourceId]);
        if ((int) $count->fetchColumn() > self::HOURLY_LIMIT) {
            throw new PaymentInboxRefusal(429, 'too_many', 'This phone has passed on too many messages in the last hour.');
        }

        $shopId = (int) $source['shop_id'];
        $payment = MpesaSms::parse($text);
        if ($payment === null) {
            $spent = MpesaSms::spentCode($text);
            if ($spent !== null) {
                self::rememberPaidOut($pdo, $shopId, $spent);
            }
            return ['recorded' => false, 'reason' => 'not_a_payment'];
        }
        if ($payment['kind'] === 'jumbe' && self::paidOut($pdo, $shopId, $payment['code'])) {
            // This phone paid this code itself: the alert is about money going out, not a customer's payment.
            return ['recorded' => false, 'reason' => 'not_a_payment'];
        }

        // A new code is added. A code a cashier already typed (a row with no message yet) gets the message's details. A
        // code whose message is already here is left exactly as it is. received_at is assigned LAST: every line above it
        // decides by the OLD received_at.
        $write = $pdo->prepare(
            'INSERT INTO payment_inbox (shop_id, mpesa_code, source_id, amount_cents, payer_name, payer_phone, account_ref, paid_at_text, message, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                source_id = IF(received_at IS NULL, VALUES(source_id), source_id),
                amount_cents = IF(received_at IS NULL, VALUES(amount_cents), amount_cents),
                payer_name = IF(received_at IS NULL, VALUES(payer_name), payer_name),
                payer_phone = IF(received_at IS NULL, VALUES(payer_phone), payer_phone),
                account_ref = IF(received_at IS NULL, VALUES(account_ref), account_ref),
                paid_at_text = IF(received_at IS NULL, VALUES(paid_at_text), paid_at_text),
                message = IF(received_at IS NULL, VALUES(message), message),
                received_at = IF(received_at IS NULL, VALUES(received_at), received_at)'
        );
        $write->execute([
            $shopId, $payment['code'], $sourceId, $payment['amountCents'], $payment['payerName'],
            $payment['payerPhone'] === null ? null : mb_substr($payment['payerPhone'], 0, 20),
            $payment['accountRef'], $payment['paidAtText'], $payment['message'],
        ]);
        return $write->rowCount() > 0
            ? ['recorded' => true, 'reason' => 'recorded']
            : ['recorded' => false, 'reason' => 'already_have_it'];
    }

    /** How long a code the shop phone paid out is remembered. */
    public const PAID_OUT_DAYS = 7;

    /**
     * Remembers that the shop phone itself paid [$code], and takes back a payment that was read from a Jumbe alert for it
     * (that alert arrived first) - unless a sale already used it, which is left for people to sort out, never undone here.
     * A payment read from the till's own "received" message stays: that is a real payment in, even when the phone paid it
     * (an owner testing their own till).
     */
    private static function rememberPaidOut(PDO $pdo, int $shopId, string $code): void
    {
        $pdo->prepare('INSERT IGNORE INTO payment_inbox_spent (shop_id, mpesa_code, seen_at) VALUES (?, ?, UTC_TIMESTAMP())')
            ->execute([$shopId, $code]);
        $pdo->prepare('DELETE FROM payment_inbox_spent WHERE seen_at < UTC_TIMESTAMP() - INTERVAL ' . self::PAID_OUT_DAYS . ' DAY')->execute();

        $found = $pdo->prepare('SELECT id, message FROM payment_inbox WHERE shop_id = ? AND mpesa_code = ? AND received_at IS NOT NULL AND claimed_sale_id IS NULL');
        $found->execute([$shopId, $code]);
        $row = $found->fetch(PDO::FETCH_ASSOC);
        if ($row && (MpesaSms::parse((string) $row['message'])['kind'] ?? null) === 'jumbe') {
            $pdo->prepare('DELETE FROM payment_inbox WHERE id = ? AND claimed_sale_id IS NULL')->execute([(int) $row['id']]);
        }
    }

    /** Whether the shop phone paid [$code] itself (within the last PAID_OUT_DAYS days). */
    private static function paidOut(PDO $pdo, int $shopId, string $code): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM payment_inbox_spent WHERE shop_id = ? AND mpesa_code = ? AND seen_at >= UTC_TIMESTAMP() - INTERVAL ' . self::PAID_OUT_DAYS . ' DAY');
        $stmt->execute([$shopId, $code]);
        return $stmt->fetchColumn() !== false;
    }

    // ------------------------------------------------------------------------------------------------------------
    // The shop's tills

    /**
     * What arrived, and what was used, in the last [$hours] hours - newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(PDO $pdo, int $shopId, int $hours): array
    {
        $hours = max(1, min(72, $hours));
        $stmt = $pdo->prepare(
            'SELECT p.*, s.label AS source_label FROM payment_inbox p
             LEFT JOIN payment_inbox_sources s ON s.id = p.source_id
             WHERE p.shop_id = ?
               AND (p.received_at > UTC_TIMESTAMP() - INTERVAL ' . $hours . ' HOUR OR p.claimed_at > UTC_TIMESTAMP() - INTERVAL ' . $hours . ' HOUR)
             ORDER BY COALESCE(p.received_at, p.claimed_at) DESC, p.id DESC
             LIMIT 200'
        );
        $stmt->execute([$shopId]);
        return array_map([self::class, 'view'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** One code of the shop, or null when the shop has never seen it. @return array<string,mixed>|null */
    public static function find(PDO $pdo, int $shopId, string $code): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT p.*, s.label AS source_label FROM payment_inbox p
             LEFT JOIN payment_inbox_sources s ON s.id = p.source_id
             WHERE p.shop_id = ? AND p.mpesa_code = ?'
        );
        $stmt->execute([$shopId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::view($row) : null;
    }

    /**
     * Uses [$code] on one sale. The same sale asking again is told yes again; any other sale is refused ("used").
     *
     * [$requireMessage]: refuse when Safaricom's message for the code has not arrived. Without it, an unseen code is
     * taken on the cashier's word and shows as not confirmed until its message arrives.
     * [$acceptAmount]: the cashier has been told the message's amount differs from the sale's and goes ahead anyway.
     *
     * @return array{confirmed:bool,payment:array<string,mixed>}
     */
    public static function claim(PDO $pdo, int $shopId, int $clientId, string $code, string $saleId, string $saleNumber, int $amountCents, bool $requireMessage, bool $acceptAmount): array
    {
        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare('SELECT * FROM payment_inbox WHERE shop_id = ? AND mpesa_code = ? FOR UPDATE');
                $lock->execute([$shopId, $code]);
                $row = $lock->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    if ($requireMessage) {
                        throw new PaymentInboxRefusal(404, 'not_received', 'No M-Pesa message with this code has arrived yet.');
                    }
                    $pdo->prepare(
                        'INSERT INTO payment_inbox (shop_id, mpesa_code, claimed_sale_id, claimed_sale_number, claimed_amount_cents, claimed_by_client_id, claimed_at)
                         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
                    )->execute([$shopId, $code, $saleId, $saleNumber, $amountCents, $clientId]);
                } elseif ($row['claimed_sale_id'] !== null) {
                    if ($row['claimed_sale_id'] !== $saleId) {
                        throw new PaymentInboxRefusal(409, 'used', 'This M-Pesa code was already used on sale ' . $row['claimed_sale_number'] . '.', [
                            'sale_number' => (string) $row['claimed_sale_number'],
                            'used_at' => self::iso($row['claimed_at']),
                        ]);
                    }
                    // The same sale again (a retry after a lost answer): nothing to change.
                } else {
                    $seen = $row['amount_cents'] === null ? null : (int) $row['amount_cents'];
                    if ($seen === null && !$acceptAmount) {
                        throw new PaymentInboxRefusal(409, 'amount_unknown', 'The amount in the M-Pesa message could not be read. Check the message on the phone.');
                    }
                    if ($seen !== null && $seen !== $amountCents && !$acceptAmount) {
                        throw new PaymentInboxRefusal(409, 'amount_differs', 'The customer paid a different amount from this sale.', ['amount_cents' => $seen]);
                    }
                    $pdo->prepare(
                        'UPDATE payment_inbox SET claimed_sale_id = ?, claimed_sale_number = ?, claimed_amount_cents = ?, claimed_by_client_id = ?, claimed_at = UTC_TIMESTAMP()
                         WHERE id = ?'
                    )->execute([$saleId, $saleNumber, $amountCents, $clientId, (int) $row['id']]);
                }
                $pdo->commit();
                break;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Two tills using the same new code at the same moment: one insert loses (duplicate key, or a deadlock
                // between the two). Looking again finds the winner's row and answers "used".
                $racing = in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1213], true);
                if (!$racing || $attempt >= 3) {
                    throw $e;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        $payment = self::find($pdo, $shopId, $code);
        if ($payment === null) {
            throw new \RuntimeException('The claimed code could not be read back.');
        }
        return ['confirmed' => $payment['message_received'], 'payment' => $payment];
    }

    /**
     * Archives a payment that arrived (it is no longer suggested at checkout on any till of the shop) or, with [$archived]
     * false, suggests it again. Nothing else changes: it stays in the list of payments received, and its code can still be
     * typed and used. Null when the shop has no message for that code (a code only typed at a till has nothing to archive).
     *
     * @return array<string,mixed>|null
     */
    public static function archive(PDO $pdo, int $shopId, int $clientId, string $code, bool $archived): ?array
    {
        if ($archived) {
            // Archiving twice keeps the first time and who did it.
            $pdo->prepare(
                'UPDATE payment_inbox SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()), archived_by_client_id = COALESCE(archived_by_client_id, ?)
                 WHERE shop_id = ? AND mpesa_code = ? AND received_at IS NOT NULL'
            )->execute([$clientId, $shopId, $code]);
        } else {
            $pdo->prepare(
                'UPDATE payment_inbox SET archived_at = NULL, archived_by_client_id = NULL WHERE shop_id = ? AND mpesa_code = ? AND received_at IS NOT NULL'
            )->execute([$shopId, $code]);
        }
        $payment = self::find($pdo, $shopId, $code);
        return $payment !== null && $payment['message_received'] ? $payment : null;
    }

    /** Frees a code a sale had used (the sale was cancelled). False when that sale does not hold the code. */
    public static function release(PDO $pdo, int $shopId, string $code, string $saleId): bool
    {
        $free = $pdo->prepare(
            'UPDATE payment_inbox SET claimed_sale_id = NULL, claimed_sale_number = NULL, claimed_amount_cents = NULL, claimed_by_client_id = NULL, claimed_at = NULL
             WHERE shop_id = ? AND mpesa_code = ? AND claimed_sale_id = ? AND received_at IS NOT NULL'
        );
        $free->execute([$shopId, $code, $saleId]);
        if ($free->rowCount() === 1) {
            return true;
        }
        // A code that was only ever typed: with the sale gone there is nothing left to remember.
        $forget = $pdo->prepare('DELETE FROM payment_inbox WHERE shop_id = ? AND mpesa_code = ? AND claimed_sale_id = ? AND received_at IS NULL');
        $forget->execute([$shopId, $code, $saleId]);
        return $forget->rowCount() === 1;
    }

    // ------------------------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function view(array $row): array
    {
        $received = $row['received_at'] !== null;
        return [
            'code' => (string) $row['mpesa_code'],
            'message_received' => $received,
            'amount_cents' => $row['amount_cents'] === null ? null : (int) $row['amount_cents'],
            'payer_name' => $row['payer_name'],
            'payer_phone' => $row['payer_phone'],
            'account_ref' => $row['account_ref'],
            'paid_at_text' => $row['paid_at_text'],
            'message' => $row['message'],
            'received_at' => self::iso($row['received_at']),
            'phone_label' => $received ? (string) ($row['source_label'] ?? '') : null,
            'archived_at' => self::iso($row['archived_at'] ?? null),
            'used' => $row['claimed_sale_id'] === null ? null : [
                'sale_id' => (string) $row['claimed_sale_id'],
                'sale_number' => (string) $row['claimed_sale_number'],
                'amount_cents' => $row['claimed_amount_cents'] === null ? null : (int) $row['claimed_amount_cents'],
                'at' => self::iso($row['claimed_at']),
            ],
        ];
    }

    /** A UTC "Y-m-d H:i:s" from the database as "Y-m-dTH:i:sZ" (null stays null). */
    private static function iso($utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        return str_replace(' ', 'T', substr((string) $utc, 0, 19)) . 'Z';
    }
}
