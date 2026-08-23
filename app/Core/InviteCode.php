<?php

namespace Platform\Core;

use PDO;

/**
 * Generates the short code a second device types in to join an existing
 * shop's sync group (see join_shop in public/index.php). Alphabet
 * excludes visually-ambiguous characters (0/O, 1/I/L) since this is
 * meant to be read off one device's screen and typed into another's
 * keyboard by hand.
 */
class InviteCode
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LENGTH = 8;

    /**
     * Generates a code guaranteed not to collide with an existing
     * shop_invites row - collisions are astronomically unlikely at this
     * alphabet/length, but the retry is cheap insurance against the
     * unique-constraint insert failing outright.
     */
    public static function generate(PDO $pdo): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = self::random();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM shop_invites WHERE code = ?');
            $stmt->execute([$code]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not generate a unique invite code.');
    }

    private static function random(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }
        return $code;
    }
}
