<?php

declare(strict_types=1);

// What MpesaSms makes of the messages M-Pesa sends a shop's phone: payments RECEIVED are read (code, amount, payer, time,
// and the owner's balance blanked out); everything else - a balance, money sent, anything that is not M-Pesa - is nothing.
//
//   php tests/verify_mpesa_sms.php
//
// The balance message below is real wording (2026-11-17, code changed). The "received" messages are the wordings these
// message types are known by; they are to be replaced with real ones from a till and a paybill as soon as we have them.

require __DIR__ . '/../app/Services/MpesaSms.php';

use Platform\Services\MpesaSms;

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $what . "\n";
    if (!$ok) {
        $failures++;
    }
}
function same($actual, $expected, string $what): void
{
    check($actual === $expected, $what . ($actual === $expected ? '' : ' - got ' . var_export($actual, true) . ', wanted ' . var_export($expected, true)));
}

// --- not payments received: nothing is read, nothing is kept -----------------------------------------------------
$balance = 'UKH3R3ABCD Confirmed. Your account balance was: M-PESA Account : Ksh0.00 M-PESA Pochi Account : Ksh0.00 on 17/11/26 at 12:47 AM. Transaction cost, Ksh0.00. Buy & sell NSE shares NOW with ZIIDI Trader on https://saf.cx/gkMQq';
same(MpesaSms::parse($balance), null, 'a balance message is not a payment');
same(MpesaSms::parse('SJK1ABC2DE Confirmed. Ksh500.00 sent to JANE DOE 0712345678 on 17/11/26 at 12:47 PM. New M-PESA balance is Ksh1,500.00. Transaction cost, Ksh7.00.'), null, 'money the owner SENT is not a payment');
same(MpesaSms::parse('SJK1ABC2DE Confirmed. Ksh500.00 paid to NAIVAS. on 17/11/26 at 12:47 PM.New M-PESA balance is Ksh1,500.00.'), null, 'money the owner PAID is not a payment');
same(MpesaSms::parse('SJK1ABC2DE Confirmed.on 17/11/26 at 12:47 PMWithdraw Ksh500.00 from 123456 - AGENT New M-PESA balance is Ksh1,500.00.'), null, 'a withdrawal is not a payment');
same(MpesaSms::parse('Your parcel was received. Thank you.'), null, 'a message that is not from M-Pesa');
same(MpesaSms::parse('DELIVERED Confirmed. Your order was received from the shop.'), null, 'an ordinary word in front of "Confirmed" is not a code');
same(MpesaSms::parse('1234567890 Confirmed. You have received Ksh500.00 from JOHN'), null, 'a number alone is not a code');
same(MpesaSms::parse(''), null, 'an empty message');
same(MpesaSms::parse('   '), null, 'only spaces');
same(MpesaSms::parse('SJK1ABC2DE Confirmed. You have received Ksh500.00 from JOHN DOE. ' . str_repeat('x', 1700)), null, 'a message far too long to be an SMS');
same(MpesaSms::parse('Hello SJK1ABC2DE Confirmed. You have received Ksh500.00 from JOHN DOE'), null, 'the code must open the message');

// --- a personal line -------------------------------------------------------------------------------------------
$personal = MpesaSms::parse("SJK1ABC2DE Confirmed.You have received Ksh1,500.00 from JOHN DOE 0712345678 on 17/11/26 at 12:47 PM  New M-PESA balance is Ksh2,000.00. Transaction cost, Ksh0.00.");
same($personal['code'] ?? null, 'SJK1ABC2DE', 'personal: the code');
same($personal['amountCents'] ?? null, 150000, 'personal: 1,500.00 is 150000 cents');
same($personal['payerName'] ?? null, 'JOHN DOE', 'personal: who paid');
same($personal['payerPhone'] ?? null, '0712345678', 'personal: their number');
same($personal['paidAtText'] ?? null, '17/11/26 12:47 PM', 'personal: when, as the message says it');
check($personal !== null && $personal['accountRef'] === null, 'personal: no account number');
check(strpos($personal['message'], '2,000.00') === false, "personal: the owner's balance is not kept");
check(strpos($personal['message'], 'balance is Ksh ***') !== false, 'personal: the balance is blanked, the rest of the sentence stays');
check(strpos($personal['message'], 'received Ksh1,500.00 from JOHN DOE') !== false, 'personal: the payment itself is kept as written');
check(strpos($personal['message'], 'Transaction cost, Ksh0.00') !== false, 'personal: an amount that is not a balance is left alone');

// --- a till (Buy Goods): the amount comes BEFORE "received", the number before the name ----------------------------
$till = MpesaSms::parse('SJK2TILL9X Confirmed. on 17/11/26 at 9:05 AM Ksh500.00 received from 254712345678 JOHN DOE. New Account balance is Ksh10,500.00. Transaction cost, Ksh2.50.');
same($till['code'] ?? null, 'SJK2TILL9X', 'till: the code');
same($till['amountCents'] ?? null, 50000, 'till: the amount');
same($till['payerName'] ?? null, 'JOHN DOE', 'till: who paid');
same($till['payerPhone'] ?? null, '254712345678', 'till: their number');
same($till['paidAtText'] ?? null, '17/11/26 9:05 AM', 'till: when');
check(strpos($till['message'], '10,500.00') === false, 'till: the till balance is not kept');
check(strpos($till['message'], 'Ksh2.50') !== false, 'till: the transaction cost is left alone');

// --- a paybill: with the account number the customer typed --------------------------------------------------------
$paybill = MpesaSms::parse('SJK3PAYB7Q Confirmed. on 17/11/26 at 1:05 PM Ksh2,350.00 received from 254712345678 JANE WANJIKU. Account Number INV-1042 New Utility balance is Ksh12,350.00');
same($paybill['amountCents'] ?? null, 235000, 'paybill: the amount');
same($paybill['payerName'] ?? null, 'JANE WANJIKU', 'paybill: who paid');
same($paybill['accountRef'] ?? null, 'INV-1042', 'paybill: the account number');
check(strpos($paybill['message'], '12,350.00') === false, 'paybill: a balance at the very end (no full stop) is not kept');

// --- Pochi la Biashara: no number, a business balance -------------------------------------------------------------
$pochi = MpesaSms::parse('SJK4POCH1A Confirmed.You have received Ksh100.00 from JOHN DOE on 17/11/26 at 12:47 PM. New business balance is Ksh100.00.');
same($pochi['amountCents'] ?? null, 10000, 'pochi: the amount');
same($pochi['payerName'] ?? null, 'JOHN DOE', 'pochi: who paid');
check($pochi !== null && $pochi['payerPhone'] === null, 'pochi: no number given');
check(strpos($pochi['message'], 'balance is Ksh ***') !== false, 'pochi: the business balance is blanked');

// --- the same things written a little differently ------------------------------------------------------------------
$masked = MpesaSms::parse('SJK5MASK2B Confirmed.You have received Ksh70.00 from MARY A 0712***678 on 1/2/27 at 7:00 AM New M-PESA balance is Ksh70.00.');
same($masked['payerPhone'] ?? null, '0712***678', 'a number with stars in it is kept as given');
same($masked['payerName'] ?? null, 'MARY A', 'and the name beside it');
same($masked['paidAtText'] ?? null, '1/2/27 7:00 AM', 'a date without leading zeros');

$messy = MpesaSms::parse("  sjk6mess3c confirmed.\nYou have received\u{00A0}Ksh 1,000 from  PETER   OTIENO\r\n0722000111 on 17/11/26 at 12:47pm");
same($messy['code'] ?? null, 'SJK6MESS3C', 'small letters, line breaks and odd spaces: the code is stored in capitals');
same($messy['amountCents'] ?? null, 100000, '"Ksh 1,000" with no cents');
same($messy['payerName'] ?? null, 'PETER OTIENO', 'doubled spaces in a name become one');
same($messy['payerPhone'] ?? null, '0722000111', 'a number on the next line');
same($messy['paidAtText'] ?? null, '17/11/26 12:47 PM', '"12:47pm"');

same(MpesaSms::parse('SJK7HALF4D Confirmed. You have received Ksh50.5 from A B')['amountCents'] ?? null, 5050, 'one decimal: 50.5 is 5050 cents');
same(MpesaSms::parse('SJK7HALF4D Confirmed. You have received Ksh0.01 from A B')['amountCents'] ?? null, 1, 'one cent');
same(MpesaSms::parse('SJK7HALF4D Confirmed. You have received Ksh.1,250.00 from A B')['amountCents'] ?? null, 125000, '"Ksh." with a full stop');

// --- a received message that cannot be fully read is still reported, never guessed ---------------------------------
$unread = MpesaSms::parse('SJK8UNRD5E Confirmed. You have received money from JOHN DOE on 17/11/26 at 12:47 PM.');
check($unread !== null, 'a received message without a readable amount is still a payment to look at');
check($unread !== null && $unread['amountCents'] === null, 'its amount is unknown, not guessed');
same($unread['payerName'] ?? null, 'JOHN DOE', 'what can be read still is');

$long = MpesaSms::parse('SJK9LONG6F Confirmed. You have received Ksh10.00 from ' . str_repeat('A', 300) . ' on 17/11/26 at 12:47 PM. ' . str_repeat('promo ', 150));
check($long !== null && mb_strlen($long['payerName']) === 120, 'a very long name is cut to 120 characters');
check($long !== null && mb_strlen($long['message']) === 500, 'the kept message is cut to 500 characters');

// --- codes as a cashier types them ----------------------------------------------------------------------------------
same(MpesaSms::normalizeCode(' ukh3r3nltu '), 'UKH3R3NLTU', 'a typed code: capitals, spaces off');
same(MpesaSms::normalizeCode('UKH3 R3NL-TU'), 'UKH3R3NLTU', 'a typed code: spaces and dashes inside are dropped');
same(MpesaSms::normalizeCode('UKH3R3N'), null, 'too short to be a code');
same(MpesaSms::normalizeCode('UKH3R3NLTU123'), null, 'too long to be a code');
same(MpesaSms::normalizeCode('ABCDEFGHIJ'), null, 'letters only is not a code');
same(MpesaSms::normalizeCode('1234567890'), null, 'digits only is not a code');
same(MpesaSms::normalizeCode('UKH3R3NL!U'), null, 'other characters are not allowed');
same(MpesaSms::normalizeCode(''), null, 'nothing typed');

echo $failures === 0 ? "MpesaSms tests passed.\n" : "$failures MpesaSms test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
