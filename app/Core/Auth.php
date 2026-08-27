<?php

namespace Platform\Core;

use PDO;

/**
 * Bearer-token auth for devices. The token is a 256-bit value the
 * device generated for it at register_device time; only its sha256
 * hash is ever stored, looked up via an indexed exact-match WHERE -
 * NOT password_hash()/bcrypt, which would force a linear scan of every
 * client row on every authenticated request (bcrypt hashes can't be
 * looked up by index) for no security benefit on an already-high-entropy
 * server-generated token.
 */
class Auth
{
    /**
     * Joins shops in so every settlement field (business_name,
     * subaccount_code, percentage_charge, ...) comes from the SHOP, not
     * this specific device - a shop has one bank account/subaccount
     * regardless of which of its devices rings up a given sale, so a
     * device newly joined via an invite code must see the same settled
     * status as every other device in that shop, not "not settled yet"
     * just because it personally never filled in the settlement form.
     * `clients.status` (kept, not joined away) stays a genuinely
     * per-device fact - registration grace-window and admin-disable.
     */
    public static function client(PDO $pdo): ?array
    {
        $header = self::authorizationHeader();
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return null;
        }
        $stmt = $pdo->prepare('
            SELECT clients.*, shops.business_name, shops.settlement_type, shops.bank_code,
                   shops.account_number, shops.account_name, shops.subaccount_code,
                   shops.percentage_charge, shops.is_verified
            FROM clients
            JOIN shops ON shops.id = clients.shop_id
            WHERE clients.api_key_hash = ?
            LIMIT 1
        ');
        $stmt->execute([hash('sha256', trim($matches[1]))]);
        $client = $stmt->fetch();
        return $client ?: null;
    }

    /**
     * $_SERVER['HTTP_AUTHORIZATION'] is unset under some Apache/PHP
     * configs (a known gotcha - see docs/verification-checklist.md item
     * 4), so this also falls back to getallheaders(). That fallback
     * must be a case-INSENSITIVE key search: HTTP header names are
     * case-insensitive by spec, but a plain array lookup isn't - Dart's
     * http client sends "authorization" lowercase, which a literal
     * getallheaders()['Authorization'] lookup silently misses even
     * though the header is right there (confirmed via temporary
     * request logging - curl defaults to the capitalized form, which is
     * why curl-based testing didn't catch this).
     */
    private static function authorizationHeader(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header !== '' || !function_exists('getallheaders')) {
            return (string) $header;
        }
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return (string) $value;
            }
        }
        return '';
    }

    public static function requireClient(PDO $pdo): array
    {
        $client = self::client($pdo);
        if (!$client) {
            jsonResponse(['status' => false, 'message' => 'Invalid or missing API key.'], 401);
        }
        if ($client['status'] === 'disabled') {
            jsonResponse(['status' => false, 'message' => 'This device has been disabled.'], 403);
        }
        // Every authenticated call passes through here, making this the
        // one place that can stand in for a real heartbeat without
        // needing a separate ping endpoint - the app already calls an
        // authenticated action (sync) on its own periodic timer every
        // 2 minutes, so this naturally reflects real recent activity.
        // Fire-and-forget: never worth failing an otherwise-successful
        // request over this bookkeeping write.
        $pdo->prepare('UPDATE clients SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$client['id']]);
        return $client;
    }
}
