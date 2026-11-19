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
 *
 * M-PESA Jumbe ("split notifications", 2026): a till or paybill that has it switched on also sends a short payment alert,
 * without balances, to the phones its owner nominated - "<CODE> Confirmed. Amount: Ksh60.00. Paid to: <SHOP> TILL: <no>
 * From: <payer> Date: <date> at <time>. MPESA Jumbe! ..." - with no word "received" in it. That is a payment received
 * too (kind 'jumbe'): only a phone the till nominated gets it, so the money went into that till - even when the same phone
 * paid it (an owner testing their own till). The CUSTOMER gets a Jumbe receipt of their own ("You sent: ... Received by:
 * ... Your JumbeID: ..."); that one, like every other message about money the phone's owner paid or sent, is never a
 * payment received.
 */
final class MpesaSms
{
    /** The longest SMS worth reading (a long M-Pesa message is about 3 parts). */
    public const MAX_LENGTH = 1600;

    /** "Ksh60.00", "Ksh 1,500", "Ksh.60", "KES 60.00": the number. */
    private const MONEY = '(?:Ksh|KES)\.?\s*([\d,]+(?:\.\d{1,2})?)';

    /** The labels of a Jumbe alert's parts ("Paid to:", "TILL:", ...), so one part's value stops where the next begins. */
    private const JUMBE_LABEL = '(?:Amount|Paid\s+to|TILL|Till\s+(?:No\.?|Number)|Pay\s?bill(?:\s+(?:No\.?|Number))?|Business\s+(?:No\.?|Number)|Account(?:\s+(?:No\.?|Number))?|Acc\.?(?:\s+No\.?)?|From|Customer|Payer|JumbeID|Date|Time|Ref(?:erence)?)\s*:';

    /**
     * @return array{code:string,kind:string,amountCents:?int,payerName:?string,payerPhone:?string,accountRef:?string,paidAtText:?string,message:string}|null
     *         kind: 'received' (the till's / paybill's / line's own message) or 'jumbe' (an M-PESA Jumbe payment alert)
     */
    public static function parse(string $raw): ?array
    {
        $head = self::head($raw);
        if ($head === null) {
            return null;
        }
        [$code, $text] = $head;
        // Money the phone's owner paid or sent - including the customer's own Jumbe receipt, whose "Received by:" would
        // otherwise read as a payment received - is never one.
        if (self::isPaidOut($text)) {
            return null;
        }
        // A Jumbe alert first: its labelled parts ("Amount:", "From:", "Paid to:") are never in the other messages, and
        // read as an ordinary message it would lose its amount should Safaricom ever put "received" in it.
        $jumbe = self::jumbeAlert($code, $text);
        if ($jumbe !== null) {
            return $jumbe;
        }
        if (preg_match('/\breceived\b/i', $text) === 1) {
            return self::received($code, $text);
        }
        return null;
    }

    /**
     * The message as one line, and its code when it opens with "<CODE> Confirmed"; null when it is not such a message.
     *
     * @return array{0:string,1:string}|null
     */
    private static function head(string $raw): ?array
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
        return [$code, $text];
    }

    /** Whether the message is about money the phone's owner paid or sent. */
    private static function isPaidOut(string $text): bool
    {
        return preg_match('/\bYou\s+(?:have\s+)?(?:sent|paid)\b/i', $text) === 1
            || preg_match('/\bYour\s+JumbeID\b/i', $text) === 1
            || preg_match('/\bReceived\s+by\s*:/i', $text) === 1
            || preg_match('/' . self::MONEY . '\s+(?:sent|paid)\s+to\b/i', $text) === 1;
    }

    /**
     * An M-PESA Jumbe payment alert: "Amount:", who paid ("From:"), and where it was paid ("Paid to:" / "TILL:" / ...).
     *
     * @return array<string,mixed>|null
     */
    private static function jumbeAlert(string $code, string $text): ?array
    {
        if (preg_match('/\bAmount\s*:/i', $text) !== 1
            || preg_match('/\b(?:From|Customer|Payer|JumbeID)\s*:/i', $text) !== 1
            || preg_match('/\b(?:Paid\s+to|TILL|Till\s+(?:No\.?|Number)|Pay\s?bill(?:\s+(?:No\.?|Number))?|Business\s+(?:No\.?|Number))\s*:/i', $text) !== 1) {
            return null;
        }
        $amount = null;
        if (preg_match('/\bAmount\s*:\s*' . self::MONEY . '/i', $text, $m) === 1) {
            $amount = self::cents($m[1]);
        }

        [$payerName, $payerPhone] = [null, null];
        // (.*?) may be empty: "From:" with nothing after it is no payer, not the next part ("Date: ...").
        if (preg_match('/\b(?:From|Customer|Payer|JumbeID)\s*:(.*?)(?=\s+' . self::JUMBE_LABEL . '|\.\s|\.$|$)/i', $text, $m) === 1) {
            [$payerName, $payerPhone] = self::who($m[1]);
        }

        $paidAt = null;
        $date = '(\d{4}[\-\/.]\d{1,2}[\-\/.]\d{1,2}|\d{1,2}[\-\/.]\d{1,2}[\-\/.]\d{2,4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9},?\s+\d{2,4})';
        $time = '(\d{1,2}:\d{2}(?::\d{2})?)(?:\s*([AP]M)\b)?';
        if (preg_match('/\bDate\s*:\s*' . $date . '(?:\s*,?\s*(?:at\s+)?' . $time . ')?/i', $text, $m) === 1) {
            $paidAt = $m[1];
            if (($m[2] ?? '') !== '') {
                $paidAt .= ' ' . $m[2] . (($m[3] ?? '') !== '' ? ' ' . strtoupper($m[3]) : '');
            } elseif (preg_match('/\bTime\s*:\s*' . $time . '/i', $text, $t) === 1) {
                $paidAt .= ' ' . $t[1] . (($t[2] ?? '') !== '' ? ' ' . strtoupper($t[2]) : '');
            }
            $paidAt = mb_substr($paidAt, 0, 40);
        }

        $account = null;
        if (preg_match('/\b(?:Account(?:\s+(?:No\.?|Number))?|Acc\.?(?:\s+No\.?)?)\s*:\s*([A-Za-z0-9][A-Za-z0-9\-_\/#]{0,39})/i', $text, $m) === 1) {
            $account = $m[1];
        }

        return [
            'code' => $code,
            'kind' => 'jumbe',
            'amountCents' => $amount,
            'payerName' => $payerName,
            'payerPhone' => $payerPhone,
            'accountRef' => $account,
            'paidAtText' => $paidAt,
            'message' => mb_substr(self::withoutBalances($text), 0, 500),
        ];
    }

    /**
     * A message with "received" in it: the till's, paybill's, Pochi's or line's own.
     *
     * @return array<string,mixed>
     */
    private static function received(string $code, string $text): array
    {
        $amount = null;
        $money = 'Ksh\.?\s*([\d,]+(?:\.\d{1,2})?)';
        if (preg_match('/\breceived\s+' . $money . '/i', $text, $m) === 1
            || preg_match('/' . $money . '\s+(?:has\s+been\s+|was\s+)?received\b/i', $text, $m) === 1) {
            $amount = self::cents($m[1]);
        }

        $payerName = null;
        $payerPhone = null;
        if (preg_match('/\bfrom\s+(.+?)(?=\s+on\s+\d{1,2}\/\d{1,2}\/\d{2,4}|\s+Account\s+Number\b|\s+New\s|\.\s|\.$|$)/i', $text, $m) === 1) {
            [$payerName, $payerPhone] = self::who($m[1]);
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
            'kind' => 'received',
            'amountCents' => $amount,
            'payerName' => $payerName,
            'payerPhone' => $payerPhone,
            'accountRef' => $account,
            'paidAtText' => $paidAt,
            'message' => mb_substr(self::withoutBalances($text), 0, 500),
        ];
    }

    /**
     * Who paid, as a message writes it ("JOHN DOE 0712345678", "254712345678 JOHN DOE", "NK_74"): the name and the number.
     *
     * @return array{0:?string,1:?string}
     */
    private static function who(string $written): array
    {
        $who = trim($written, " \t.,-");
        $phone = null;
        if (preg_match('/\+?\d[\d*Xx]{8,13}/', $who, $found) === 1) {
            $phone = $found[0];
            $who = trim(str_replace($found[0], ' ', $who), " \t.,-");
            $who = trim((string) preg_replace('/\s+/', ' ', $who));
        }
        return [$who === '' ? null : mb_substr($who, 0, 120), $phone];
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
