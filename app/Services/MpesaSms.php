<?php

declare(strict_types=1);

namespace Platform\Services;

/**
 * Reads one M-Pesa SMS and says whether it is a payment the shop RECEIVED, and if so its code, amount, payer and time.
 *
 * Only "received" messages count. Everything else that M-Pesa sends the same phone - a balance, money the owner sent or
 * withdrew, airtime, a reversal - is the owner's own business: parse() answers null for it and nothing of it is kept.
 * The balance printed at the end of a payment message is the owner's too, so `message` has it blanked out.
 *
 * The exact wording differs between a till (Buy Goods), a paybill, Pochi la Biashara and a personal line, and Safaricom
 * changes it now and then, so this does not match whole sentences: it looks for the parts every received message has
 * ("<CODE> Confirmed", "received", a Ksh amount next to it, "from <who>", "on <date> at <time>") wherever they are.
 * A received message whose amount cannot be read is still reported (amountCents null) so it is seen, not lost.
 */
final class MpesaSms
{
    /** The longest SMS worth reading (a long M-Pesa message is about 3 parts). */
    public const MAX_LENGTH = 1600;

    /**
     * @return array{code:string,amountCents:?int,payerName:?string,payerPhone:?string,accountRef:?string,paidAtText:?string,message:string}|null
     */
    public static function parse(string $raw): ?array
    {
        if (strlen($raw) > self::MAX_LENGTH * 4) {
            return null;
        }
        // One line, plain spaces (messages arrive with non-breaking spaces, line breaks and doubled spaces).
        $text = trim((string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $raw));
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return null;
        }
        if (preg_match('/^([A-Za-z0-9]{8,12})\s+Confirmed\b/i', $text, $head) !== 1) {
            return null;
        }
        $code = strtoupper($head[1]);
        // A real code has letters and digits; this keeps an ordinary word in front of "Confirmed" from passing as one.
        if (preg_match('/[A-Z]/', $code) !== 1 || preg_match('/\d/', $code) !== 1) {
            return null;
        }
        if (preg_match('/\breceived\b/i', $text) !== 1) {
            return null;
        }

        $amount = null;
        $money = 'Ksh\.?\s*([\d,]+(?:\.\d{1,2})?)';
        if (preg_match('/\breceived\s+' . $money . '/i', $text, $m) === 1
            || preg_match('/' . $money . '\s+(?:has\s+been\s+|was\s+)?received\b/i', $text, $m) === 1) {
            $amount = self::cents($m[1]);
        }

        $payerName = null;
        $payerPhone = null;
        if (preg_match('/\bfrom\s+(.+?)(?=\s+on\s+\d{1,2}\/\d{1,2}\/\d{2,4}|\s+Account\s+Number\b|\s+New\s|\.\s|\.$|$)/i', $text, $m) === 1) {
            $who = trim($m[1], " \t.,-");
            if (preg_match('/\+?\d[\d*Xx]{8,13}/', $who, $phone) === 1) {
                $payerPhone = $phone[0];
                $who = trim(str_replace($phone[0], ' ', $who), " \t.,-");
                $who = trim((string) preg_replace('/\s+/', ' ', $who));
            }
            if ($who !== '') {
                $payerName = mb_substr($who, 0, 120);
            }
        }

        $paidAt = null;
        if (preg_match('/\bon\s+(\d{1,2}\/\d{1,2}\/\d{2,4})\s+at\s+(\d{1,2}:\d{2})\s*([AP]M)\b/i', $text, $m) === 1) {
            $paidAt = $m[1] . ' ' . $m[2] . ' ' . strtoupper($m[3]);
        }

        $account = null;
        if (preg_match('/\bAccount\s+Number\s+([A-Za-z0-9][A-Za-z0-9\-_\/#]{0,39})/i', $text, $m) === 1) {
            $account = $m[1];
        }

        return [
            'code' => $code,
            'amountCents' => $amount,
            'payerName' => $payerName,
            'payerPhone' => $payerPhone,
            'accountRef' => $account,
            'paidAtText' => $paidAt,
            'message' => mb_substr(self::withoutBalances($text), 0, 500),
        ];
    }

    /** A code as a cashier may type it ("ukh3r3nltu ", "UKH3 R3NLTU") in its stored form, or null when it cannot be one. */
    public static function normalizeCode(string $typed): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s\-]+/', '', $typed));
        if (preg_match('/^[A-Z0-9]{8,12}$/', $code) !== 1 || preg_match('/[A-Z]/', $code) !== 1 || preg_match('/\d/', $code) !== 1) {
            return null;
        }
        return $code;
    }

    /** "1,500.5" -> 150050; null when it is not an amount or is absurdly large. */
    private static function cents(string $printed): ?int
    {
        $plain = str_replace(',', '', $printed);
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $plain, $m) !== 1) {
            return null;
        }
        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** The message with every amount that follows the word "balance" (within its sentence) blanked. */
    private static function withoutBalances(string $text): string
    {
        return (string) preg_replace_callback(
            '/\bbalance\b[^.]*?(?:\.\d{1,2}[^.]*?)*(?=\.\s|\.$|$)/i',
            static fn (array $m): string => (string) preg_replace('/Ksh\.?\s*[\d,]+(?:\.\d{1,2})?/i', 'Ksh ***', $m[0]),
            $text
        );
    }
}
