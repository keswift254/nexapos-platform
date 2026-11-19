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
// the real Pochi wording (the owner's screenshot, 2026-11-20; the code and payer are made up here)
$pochiReal = MpesaSms::parse("SJK4POCH1B Confirmed.You have\nreceived Ksh100.00 from JANE\nAKINYI on 2/11/26 at 6:38 PM New\nPochi balance is Ksh100.00. To access\nyour funds, Dial *334#,select Pochi la\nBiashara & Withdraw funds.");
same($pochiReal['kind'] ?? null, 'received', 'pochi (real wording): a payment received');
same($pochiReal['code'] ?? null, 'SJK4POCH1B', 'pochi (real wording): the code');
same($pochiReal['amountCents'] ?? null, 10000, 'pochi (real wording): the amount');
same($pochiReal['payerName'] ?? null, 'JANE AKINYI', 'pochi (real wording): who paid, across a line break');
same($pochiReal['paidAtText'] ?? null, '2/11/26 6:38 PM', 'pochi (real wording): when');
check($pochiReal !== null && strpos($pochiReal['message'], 'Pochi balance is Ksh ***. To access your funds') !== false && strpos($pochiReal['message'], 'Ksh100.00. To') === false, 'pochi (real wording): the Pochi balance is blanked, the rest kept');

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

// --- M-PESA Jumbe: the alert a till sends the phones its owner nominated (2026-11-19, the owner's screenshot; the shop
//     name, till number, payer and date were covered in it, so those are made up here) -----------------------------------
$jumbeSms = "UKJB430TS5 Confirmed.\nAmount: Ksh60.00.\nPaid to: MAMA MBOGA KUNDI\nTILL: 5678900\nFrom: NK_74\nDate: 2026-11-19 at 18:27:37.\n\nMPESA Jumbe! A public-friendly SMS you can display without exposing personal MPESA information.";
$jumbe = MpesaSms::parse($jumbeSms);
check($jumbe !== null, 'jumbe: the alert is a payment received');
same($jumbe['kind'] ?? null, 'jumbe', 'jumbe: marked as a Jumbe alert');
same($jumbe['code'] ?? null, 'UKJB430TS5', 'jumbe: the code');
same($jumbe['amountCents'] ?? null, 6000, 'jumbe: Ksh60.00 is 6000 cents');
same($jumbe['payerName'] ?? null, 'NK_74', 'jumbe: who paid (their JumbeID)');
check($jumbe !== null && $jumbe['payerPhone'] === null, 'jumbe: no number given');
same($jumbe['paidAtText'] ?? null, '2026-11-19 18:27:37', 'jumbe: when, as the message says it');
check($jumbe !== null && $jumbe['accountRef'] === null, 'jumbe: a till has no account number');
check($jumbe !== null && strpos($jumbe['message'], 'Paid to: MAMA MBOGA KUNDI TILL: 5678900') !== false, 'jumbe: the message is kept as written, on one line');

$jumbeName = MpesaSms::parse('UKJB430TS6 Confirmed. Amount: Ksh1,250.50. Paid to: SHOP TILL: 123456 From: JOHN DOE 0712***678 Date: 19/11/26 at 6:27 PM. MPESA Jumbe!');
same($jumbeName['amountCents'] ?? null, 125050, 'jumbe: Ksh1,250.50');
same($jumbeName['payerName'] ?? null, 'JOHN DOE', 'jumbe: a name in From');
same($jumbeName['payerPhone'] ?? null, '0712***678', 'jumbe: a hidden number in From');
same($jumbeName['paidAtText'] ?? null, '19/11/26 6:27 PM', 'jumbe: a day/month/year date with AM/PM');

$jumbePaybill = MpesaSms::parse('UKJB430TS7 Confirmed. Amount: KES 2,350.00. Paybill: 400200 Account: INV-1042 From: JW_12 Date: 19-11-2026 Time: 09:05:12');
same($jumbePaybill['amountCents'] ?? null, 235000, 'jumbe paybill: "KES 2,350.00"');
same($jumbePaybill['accountRef'] ?? null, 'INV-1042', 'jumbe paybill: the account number');
same($jumbePaybill['payerName'] ?? null, 'JW_12', 'jumbe paybill: who paid, stopping at the next label');
same($jumbePaybill['paidAtText'] ?? null, '19-11-2026 09:05:12', 'jumbe paybill: a separate Time');

$jumbeWords = MpesaSms::parse('UKJB430TS8 Confirmed. Amount: Ksh500.00. Paid to: SHOP. TILL: 123456. From: AB_01. Date: 19 Nov 2026 at 10:00.');
same($jumbeWords['payerName'] ?? null, 'AB_01', 'jumbe: a full stop after each part');
same($jumbeWords['paidAtText'] ?? null, '19 Nov 2026 10:00', 'jumbe: a date with the month as a word');

$jumbeNoAmount = MpesaSms::parse('UKJB430TS9 Confirmed. Amount: sixty. Paid to: SHOP TILL: 123456 From: NK_74');
check($jumbeNoAmount !== null && $jumbeNoAmount['amountCents'] === null, 'jumbe: an amount that cannot be read is unknown, not guessed');

// the real alert puts every part on its own line; "From:" can come with nothing after it
$jumbeNoPayer = MpesaSms::parse("UKJB431TS5 Confirmed.\nAmount: Ksh60.00.\nPaid to: SHOP KUNDI\nTILL: 123456\nFrom: \nDate: 2026-11-19 at 18:27:37.\n\nMPESA Jumbe! A public-friendly SMS you can display without exposing personal MPESA information.");
check($jumbeNoPayer !== null && $jumbeNoPayer['amountCents'] === 6000, 'jumbe: an empty "From:" is still a payment');
check($jumbeNoPayer !== null && $jumbeNoPayer['payerName'] === null && $jumbeNoPayer['payerPhone'] === null, 'jumbe: an empty "From:" is no payer, not the next line');
same($jumbeNoPayer['paidAtText'] ?? null, '2026-11-19 18:27:37', 'jumbe: the date after an empty "From:" is still read');
$jumbeCrlf = MpesaSms::parse("UKJB431TS6 Confirmed.\r\nAmount: Ksh60.00.\r\nPaid to: SHOP KUNDI\r\nTILL: 123456\r\nFrom: NK_74\r\nDate: 2026-11-19 at 18:27:37.");
same($jumbeCrlf['payerName'] ?? null, 'NK_74', 'jumbe: Windows line breaks read the same');

$jumbeReceivedWord = MpesaSms::parse('UKJB431TS1 Confirmed. Payment received. Amount: Ksh75.00. TILL: 123456 From: NK_74 Date: 2026-11-19 at 18:30:00.');
same($jumbeReceivedWord['kind'] ?? null, 'jumbe', 'jumbe: read as an alert even with the word "received" in it');
same($jumbeReceivedWord['amountCents'] ?? null, 7500, 'jumbe: and its amount is still read');

same(MpesaSms::parse('UKJB431TS2 Confirmed. Amount: Ksh60.00. Paid to: SHOP TILL: 123456 Date: 2026-11-19 at 18:27:37.'), null, 'jumbe: without who paid it is not read as a payment');
same(MpesaSms::parse('UKJB431TS3 Confirmed. Amount: Ksh60.00. From: NK_74 Date: 2026-11-19 at 18:27:37.'), null, 'jumbe: without where it was paid it is not read as a payment');
same(MpesaSms::parse('UKJB431TS4 Confirmed. Paid to: SHOP TILL: 123456 From: NK_74 Ksh60.00'), null, 'jumbe: without "Amount:" it is not read as a payment');

// --- money the phone's owner PAID: never a payment received ---------------------------------------------------------
// The customer's Jumbe receipt, as Safaricom showed it (its FAQ, quoted by techweez.com 2026-10-06).
$customerReceipt = 'UFAL477KQJ Confirmed. You sent: Ksh2,000.00. Received by: Petrol Station Till. TILL: 5678900. Your JumbeID: NK_74. Date: 2026-06-10 at 14:56:38.';
same(MpesaSms::parse($customerReceipt), null, "the customer's Jumbe receipt is money paid out, not received (its \"Received by:\" must not count)");
same(MpesaSms::parse('UFAL477KQK Confirmed. Amount: Ksh60.00. Paid to: SHOP TILL: 123456 From: NK_74. Your JumbeID: NK_74.'), null, 'labelled like an alert but saying "Your JumbeID": paid out');
same(MpesaSms::parse('UFAL477KQL Confirmed. You paid: Ksh60.00. Paid to: SHOP TILL: 123456 From: NK_74'), null, 'labelled like an alert but saying "You paid": paid out');
same(MpesaSms::parse('SJK1ABC2DE Confirmed. Ksh500.00 paid to NAIVAS. on 17/11/26 at 12:47 PM.New M-PESA balance is Ksh1,500.00.'), null, 'a till payment the owner made is not money received');
same(MpesaSms::parse('SJK1ABC2DF Confirmed. Ksh500.00 sent to JANE DOE 0712345678 on 17/11/26 at 12:47 PM. New M-PESA balance is Ksh1,500.00.'), null, 'money the owner sent is not money received');
same(MpesaSms::parse('SJK1ABC2DG Confirmed. Ksh500.00 sent to KPLC PREPAID for account 123 on 17/11/26 at 12:47 PM.'), null, 'a paybill payment the owner made is not money received');
same($personal['kind'] ?? null, 'received', 'a message with "received" is marked as one');

// --- a bank's message about money paid in through M-Pesa: Co-op Bank (the owner's screenshot, 2026-11-20; the name,
//     amount, payer and account were covered in it, so those are made up here) ------------------------------------------
$coopSms = "Dear MAMA MBOGA T/A NEXAPOS, you have received Ksh. 200.00 from JOHN KAMAU MUSU for 01192012345649 on 11/15/2026 at 17:32:35. MPESA Ref. UKFQV1WVG3..";
$coop = MpesaSms::parse($coopSms);
check($coop !== null, 'co-op: money received through M-Pesa is a payment received');
same($coop['kind'] ?? null, 'bank', 'co-op: marked as a bank message');
same($coop['code'] ?? null, 'UKFQV1WVG3', 'co-op: the M-Pesa code is the one after "MPESA Ref."');
same($coop['amountCents'] ?? null, 20000, 'co-op: "Ksh. 200.00" is 20000 cents');
same($coop['payerName'] ?? null, 'JOHN KAMAU MUSU', 'co-op: who paid, up to "for"');
check($coop !== null && $coop['payerPhone'] === null, 'co-op: no number given');
same($coop['accountRef'] ?? null, '01192012345649', 'co-op: the account it was paid into');
same($coop['paidAtText'] ?? null, '11/15/2026 17:32:35', 'co-op: when, as the message says it (month/day/year)');
check($coop !== null && strpos($coop['message'], 'MPESA Ref. UKFQV1WVG3') !== false, 'co-op: the message is kept as written');
$coopLines = MpesaSms::parse("Dear MAMA MBOGA T/A NEXAPOS, you have\nreceived Ksh. 1,250.50 from 2547***45678 JOHN KAMAU\nMUSU for 0119****5649 on 11/15/2026 at\n17:32:35. MPESA Ref. UKFQV1WVG4.. Thank you for banking with us.");
same($coopLines['amountCents'] ?? null, 125050, 'co-op: line breaks and a thousands comma');
same($coopLines['payerName'] ?? null, 'JOHN KAMAU MUSU', 'co-op: a hidden number in front of the name is split off');
same($coopLines['payerPhone'] ?? null, '2547***45678', 'co-op: and kept as the number');
same($coopLines['accountRef'] ?? null, '0119****5649', 'co-op: a hidden account number; "for banking" later on is not an account');
same($coopLines['code'] ?? null, 'UKFQV1WVG4', 'co-op: the code before the closing dots');
same(MpesaSms::parse('Dear MAMA MBOGA, you have received Ksh 75 from JANE for 01192012345649 on 11/15/2026. M-PESA Ref: UKFQV1WVG5')['amountCents'] ?? null, 7500, 'co-op: "Ksh 75", "M-PESA Ref:" and a date with no time');
$coopNoAccount = MpesaSms::parse('Dear MAMA MBOGA, you have received Ksh. 300.00 from JANE WANJIKU on 11/15/2026 at 10:00:00. MPESA Ref. UKFQV1WVG9. Thank you for banking with us.');
same($coopNoAccount['payerName'] ?? null, 'JANE WANJIKU', 'co-op: who paid, up to the date when there is no "for"');
check($coopNoAccount !== null && $coopNoAccount['accountRef'] === null, 'co-op: no account number given: "for banking" is not one');
same(MpesaSms::parse('Dear MAMA MBOGA, you have received Ksh. 200.00 from JOHN for 01192012345649 on 11/15/2026 at 17:32:35.'), null, 'co-op: without the M-Pesa reference it is not read');
same(MpesaSms::parse('Dear MAMA MBOGA, Ksh. 200.00 has been debited from 01192012345649 on 11/15/2026. MPESA Ref. UKFQV1WVG6'), null, 'co-op: money going out is not received');
same(MpesaSms::parse('Dear MAMA MBOGA, you have sent Ksh. 200.00 to JOHN on 11/15/2026. MPESA Ref. UKFQV1WVG7. You have received a bonus.'), null, 'co-op: money the owner sent is never a payment');
same(MpesaSms::parse('Dear MAMA MBOGA, you have received Ksh. 200.00 from JOHN. MPESA Ref. RECEIVEDOK'), null, 'co-op: a "reference" with no digits is not a code');
same(MpesaSms::parse('Dear MAMA MBOGA, you have received a new statement. MPESA Ref. UKFQV1WVG8'), null, 'co-op: no amount received, no payment');

// --- who the SMS came from: a person's number or email, or M-Pesa / a bank -------------------------------------------
foreach (['+254 712 345 678', '+254712345678', '0712345678', '254712345678', '0712 345 678', '(0712) 345-678', '712345678', ' 0712.345.678 ', 'someone@icloud.com'] as $person) {
    check(MpesaSms::fromPerson($person), "a person's sender: '$person'");
}
foreach (['MPESA', 'M-PESA', 'Equity Bank', 'Co-opBank', 'ABSA', 'KCB', 'Safaricom', '40404', '123456', 'MPESA 0712345678', ''] as $name) {
    check(!MpesaSms::fromPerson($name), "not a person's sender: '$name'");
}

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
