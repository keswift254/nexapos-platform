<?php

declare(strict_types=1);

// "Manual Till/Paybill": the shop's payment inbox, end to end over HTTP. Included by run.php (uses its request(), check(),
// register(), concurrentRequests(), $baseUrl and $pdo).
//
//  - only an administrator of the shop, with their own password, can set a phone up or remove it;
//  - a phone's key is given once and only its hash is kept;
//  - a phone passes SMS on: payments RECEIVED are kept (without the owner's balance), anything else is dropped;
//  - any till of the shop can list and check; a code is used on ONE sale, also when two tills ask at the same moment.

$inbox = static function (string $action, string $method, ?array $body, array $headers, array $query = []) use ($baseUrl): array {
    $ch = curl_init($baseUrl . '?' . http_build_query(array_merge(['action' => $action], $query)));
    $all = array_merge(['Accept: application/json'], $headers);
    $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
    if ($body !== null) {
        $all[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    $options[CURLOPT_HTTPHEADER] = $all;
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
};

// ---- a shop: its main device, a lite till that joined, and five people ------------------------------------------------
$inboxOwner = register($baseUrl, 'inbox-owner-device');
$inboxTill = register($baseUrl, 'inbox-lite-till', 'lite');
$inboxOther = register($baseUrl, 'inbox-other-shop');
check($inboxOwner['status'] === 201 && $inboxTill['status'] === 201 && $inboxOther['status'] === 201, 'Could not register the payment inbox devices.');
$ownerAuth = ['Authorization: Bearer ' . $inboxOwner['body']['api_key']];
$tillAuth = ['Authorization: Bearer ' . $inboxTill['body']['api_key']];
$otherAuth = ['Authorization: Bearer ' . $inboxOther['body']['api_key']];
$tillInvite = request($baseUrl, 'generate_invite', 'POST', [], $ownerAuth);
check(request($baseUrl, 'join_shop', 'POST', ['invite_code' => $tillInvite['body']['code']], $tillAuth)['status'] === 200, 'The till could not join the payment inbox shop.');
$inboxShopId = (int) $pdo->query("SELECT shop_id FROM clients WHERE device_id = 'inbox-owner-device'")->fetchColumn();
$otherShopId = (int) $pdo->query("SELECT shop_id FROM clients WHERE device_id = 'inbox-other-shop'")->fetchColumn();

const INBOX_ADMIN_ROLE = '00000000-0000-4000-8000-000000000001';
const INBOX_MANAGER_ROLE = '00000000-0000-4000-8000-000000000002';
const INBOX_CASHIER_ROLE = '00000000-0000-4000-8000-000000000003';
$people = [
    'admin' => ['id' => '10000000-0000-4000-8000-0000000000a1', 'roleId' => INBOX_ADMIN_ROLE, 'password' => 'Owner-Pass1'],
    // An app-made hash: Dart's bcrypt writes "$2a$" / "$2b$" where PHP writes "$2y$".
    'admin2b' => ['id' => '10000000-0000-4000-8000-0000000000a2', 'roleId' => INBOX_ADMIN_ROLE, 'password' => 'Second-Admin2', 'prefix' => '$2b$'],
    'manager' => ['id' => '10000000-0000-4000-8000-0000000000a3', 'roleId' => INBOX_MANAGER_ROLE, 'password' => 'Manager-Pass3'],
    'cashier' => ['id' => '10000000-0000-4000-8000-0000000000a4', 'roleId' => INBOX_CASHIER_ROLE, 'password' => 'Cashier-Pass4'],
    'gone' => ['id' => '10000000-0000-4000-8000-0000000000a5', 'roleId' => INBOX_ADMIN_ROLE, 'password' => 'Gone-Pass5', 'deleted' => true],
    'off' => ['id' => '10000000-0000-4000-8000-0000000000a6', 'roleId' => INBOX_ADMIN_ROLE, 'password' => 'Off-Pass6', 'status' => 'inactive'],
];
$revision = 0;
$userChange = static function (string $name, array $person, string $deviceId, string $updatedAt) use (&$revision): array {
    $hash = password_hash($person['password'], PASSWORD_BCRYPT, ['cost' => 4]);
    if (isset($person['prefix'])) {
        $hash = $person['prefix'] . substr($hash, 4);
    }
    $payload = [
        'id' => $person['id'], 'localRev' => ++$revision, 'updatedAt' => $updatedAt, 'createdAt' => $updatedAt,
        'deletedAt' => empty($person['deleted']) ? null : $updatedAt, 'createdByDeviceId' => $deviceId, 'syncState' => 'local_only',
        'roleId' => $person['roleId'], 'name' => ucfirst($name), 'username' => $name, 'passwordHash' => $hash, 'status' => $person['status'] ?? 'active',
    ];
    return ['table_name' => 'users', 'row_id' => $person['id'], 'local_rev' => $revision, 'updated_at' => $updatedAt, 'payload' => $payload];
};
$changes = [];
foreach ($people as $name => $person) {
    $changes[] = $userChange($name, $person, 'inbox-owner-device', '2026-11-17T08:00:00.000000Z');
}
check(request($baseUrl, 'push_changes', 'POST', ['changes' => $changes], $ownerAuth)['status'] === 200, 'Could not give the payment inbox shop its people.');
// The other shop has an administrator of its own.
$stranger = ['id' => '20000000-0000-4000-8000-0000000000b1', 'roleId' => INBOX_ADMIN_ROLE, 'password' => 'Stranger-Pass7'];
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [$userChange('stranger', $stranger, 'inbox-other-shop', '2026-11-17T08:00:00.000000Z')]], $otherAuth)['status'] === 200, 'Could not give the other shop its administrator.');

$addPhone = static fn (array $headers, string $who, string $password, string $label = 'Till phone') => $inbox('payment_inbox_add_source', 'POST', ['label' => $label, 'user_id' => $who, 'password' => $password], $headers);
$forgetWrongPasswords = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM payment_inbox_auth_failures');
};

// ---- setting a phone up: an administrator of THIS shop, with their own password -----------------------------------------
check($inbox('payment_inbox_sources', 'GET', null, [])['status'] === 401, 'The phones were listed with no device key.');
$none = $inbox('payment_inbox_sources', 'GET', null, $tillAuth);
check($none['status'] === 200 && $none['body']['phones'] === [] && $none['body']['max_phones'] === 5, 'A new shop should have no phones: ' . $none['raw']);
check($addPhone([], $people['admin']['id'], $people['admin']['password'])['status'] === 401, 'A phone was set up with no device key.');

foreach (['cashier', 'manager', 'gone', 'off'] as $name) {
    $no = $addPhone($tillAuth, $people[$name]['id'], $people[$name]['password']);
    check($no['status'] === 403 && $no['body']['reason'] === 'not_admin', "$name set a phone up with their own (right) password: " . $no['raw']);
}
$no = $addPhone($tillAuth, $stranger['id'], $stranger['password']);
check($no['status'] === 403 && $no['body']['reason'] === 'not_admin', "Another shop's administrator set a phone up here: " . $no['raw']);
foreach (['', 'not-an-id', '10000000-0000-4000-8000-0000000000ff'] as $unknown) {
    $no = $addPhone($tillAuth, $unknown, 'whatever');
    check($no['status'] === 403 && $no['body']['reason'] === 'not_admin', 'An unknown person set a phone up: ' . $no['raw']);
}
check((int) $pdo->query('SELECT COUNT(*) FROM payment_inbox_auth_failures')->fetchColumn() === 0, 'Someone who is not an administrator must not count as a wrong password.');
check((int) $pdo->query('SELECT COUNT(*) FROM payment_inbox_sources')->fetchColumn() === 0, 'A refused set-up left a phone behind.');

// wrong passwords: refused, counted, and after five the shop has to wait - even with the right one
foreach (['', 'owner-pass1', 'Owner-Pass1 ', 'Cashier-Pass4', 'x'] as $i => $wrong) {
    $no = $addPhone($tillAuth, $people['admin']['id'], $wrong);
    check($no['status'] === 403 && $no['body']['reason'] === 'wrong_password', "Wrong password #$i was not refused as one: " . $no['raw']);
}
$locked = $addPhone($tillAuth, $people['admin']['id'], $people['admin']['password']);
check($locked['status'] === 429 && $locked['body']['reason'] === 'locked', 'Five wrong passwords did not lock the shop out: ' . $locked['raw']);
check($addPhone($ownerAuth, $people['admin2b']['id'], $people['admin2b']['password'])['status'] === 429, 'The lock must hold for every device and administrator of the shop.');
// the other shop is not locked by this shop's mistakes
$elsewhere = $addPhone($otherAuth, $stranger['id'], $stranger['password'], "Stranger's phone");
check($elsewhere['status'] === 201, "Another shop was locked out by this shop's wrong passwords: " . $elsewhere['raw']);
// ten minutes later
$pdo->exec('UPDATE payment_inbox_auth_failures SET failed_at = UTC_TIMESTAMP() - INTERVAL 11 MINUTE');
$added = $addPhone($tillAuth, $people['admin']['id'], $people['admin']['password'], "  Mama's\n iPhone  ");
check($added['status'] === 201 && preg_match('/^npk_[a-f0-9]{48}$/', (string) ($added['body']['key'] ?? '')) === 1, 'The administrator could not set a phone up after the wait: ' . $added['raw']);
$phoneKey = $added['body']['key'];
$phoneId = $added['body']['phone']['id'];
check($added['body']['phone']['label'] === "Mama's iPhone" && $added['body']['phone']['added_by'] === 'admin' && $added['body']['phone']['last_message_at'] === null, 'The new phone is not described right: ' . $added['raw']);
check((int) $pdo->query('SELECT COUNT(*) FROM payment_inbox_auth_failures WHERE shop_id = ' . $inboxShopId)->fetchColumn() === 0, 'The right password should clear the earlier wrong ones.');
$stored = $pdo->query('SELECT * FROM payment_inbox_sources WHERE id = ' . (int) $phoneId)->fetch(PDO::FETCH_ASSOC);
check($stored['token_hash'] === hash('sha256', $phoneKey) && !str_contains(json_encode($stored), $phoneKey), "Only the hash of a phone's key may be stored.");
$listed = $inbox('payment_inbox_sources', 'GET', null, $tillAuth);
check(count($listed['body']['phones']) === 1 && !str_contains($listed['raw'], $phoneKey) && !str_contains($listed['raw'], $stored['token_hash']), 'The list of phones gives a key or its hash away: ' . $listed['raw']);
check($inbox('payment_inbox_sources', 'GET', null, $otherAuth)['body']['phones'][0]['label'] === "Stranger's phone", "A shop must see its own phones only.");

// an app-made "$2b$" hash is read too; an empty label becomes "Phone"; five phones at most
$second = $addPhone($ownerAuth, $people['admin2b']['id'], $people['admin2b']['password'], '   ');
check($second['status'] === 201 && $second['body']['phone']['label'] === 'Phone' && $second['body']['phone']['added_by'] === 'admin2b', 'An administrator with an app-made password hash could not set a phone up: ' . $second['raw']);
check($second['body']['key'] !== $phoneKey, 'Two phones were given the same key.');
$long = $addPhone($tillAuth, $people['admin']['id'], $people['admin']['password'], str_repeat('L', 100));
check($long['status'] === 201 && mb_strlen($long['body']['phone']['label']) === 60, 'A long label should be cut to 60 characters.');
check($addPhone($tillAuth, $people['admin']['id'], $people['admin']['password'])['status'] === 201 && $addPhone($tillAuth, $people['admin']['id'], $people['admin']['password'])['status'] === 201, 'A fourth and fifth phone were refused.');
$sixth = $addPhone($tillAuth, $people['admin']['id'], $people['admin']['password']);
check($sixth['status'] === 409 && $sixth['body']['reason'] === 'too_many', 'A sixth phone was set up: ' . $sixth['raw']);

// a person who stops being an administrator can no longer do it (the newest version of them counts)
$demoted = $people['admin2b'];
$demoted['roleId'] = INBOX_CASHIER_ROLE;
check(request($baseUrl, 'push_changes', 'POST', ['changes' => [$userChange('admin2b', $demoted, 'inbox-owner-device', '2026-11-17T09:00:00.000000Z')]], $ownerAuth)['status'] === 200, 'Could not demote the second administrator.');
$no = $inbox('payment_inbox_remove_source', 'POST', ['phone_id' => $second['body']['phone']['id'], 'user_id' => $demoted['id'], 'password' => $demoted['password']], $ownerAuth);
check($no['status'] === 403 && $no['body']['reason'] === 'not_admin', 'A demoted administrator removed a phone: ' . $no['raw']);

// ---- a phone passing messages on ------------------------------------------------------------------------------------
$pass = static fn (?string $key, $text) => $inbox('payment_inbox_ingest', 'POST', ['key' => $key, 'text' => $text], []);
$tillSms = 'SJK2TILL9X Confirmed. on 17/11/26 at 9:05 AM Ksh500.00 received from 254712345678 JOHN DOE. New Account balance is Ksh10,500.00. Transaction cost, Ksh2.50.';
$balanceSms = 'UKH3R3ABCD Confirmed. Your account balance was: M-PESA Account : Ksh0.00 M-PESA Pochi Account : Ksh0.00 on 17/11/26 at 12:47 AM. Transaction cost, Ksh0.00.';

foreach ([null, '', 'npk_short', 'npk_' . str_repeat('0', 48), substr($phoneKey, 0, -1) . (substr($phoneKey, -1) === 'a' ? 'b' : 'a')] as $badKey) {
    $no = $pass($badKey, $tillSms);
    check(in_array($no['status'], [401, 422], true) && ($no['body']['recorded'] ?? false) === false, 'A message was taken from a phone with a wrong key: ' . $no['raw']);
}
check($pass($inboxTill['body']['api_key'], $tillSms)['status'] === 401, "A till's own device key must not work as a phone's key.");
check($pass($phoneKey, '')['status'] === 422 && $pass($phoneKey, ['an', 'array'])['status'] === 422 && $pass($phoneKey, str_repeat('x', 1601))['status'] === 422, 'An empty, non-text or far too long message was not refused.');
check((int) $pdo->query('SELECT COUNT(*) FROM payment_inbox')->fetchColumn() === 0, 'A refused message was stored.');

$dropped = $pass($phoneKey, $balanceSms);
check($dropped['status'] === 200 && $dropped['body']['recorded'] === false && $dropped['body']['reason'] === 'not_a_payment', 'A balance message was not dropped: ' . $dropped['raw']);
check((int) $pdo->query('SELECT COUNT(*) FROM payment_inbox')->fetchColumn() === 0, "The owner's balance message was stored.");

$taken = $pass($phoneKey, $tillSms);
check($taken['status'] === 200 && $taken['body']['recorded'] === true && $taken['body']['reason'] === 'recorded', 'A payment message was not recorded: ' . $taken['raw']);
$row = $pdo->query("SELECT * FROM payment_inbox WHERE mpesa_code = 'SJK2TILL9X'")->fetch(PDO::FETCH_ASSOC);
check((int) $row['shop_id'] === $inboxShopId && (int) $row['source_id'] === (int) $phoneId && (int) $row['amount_cents'] === 50000
    && $row['payer_name'] === 'JOHN DOE' && $row['payer_phone'] === '254712345678' && $row['paid_at_text'] === '17/11/26 9:05 AM'
    && $row['received_at'] !== null && $row['claimed_sale_id'] === null, 'The payment was not stored as the message says: ' . json_encode($row));
check(!str_contains((string) $row['message'], '10,500') && str_contains((string) $row['message'], 'Ksh500.00 received from'), "The stored message must keep the payment and drop the owner's balance: " . $row['message']);
check(abs(strtotime($row['received_at'] . ' UTC') - time()) < 120, 'received_at is not the current UTC time: ' . $row['received_at']);

// the very same message again (the phone retried), and a made-up "correction" of it, change nothing
$again = $pass($phoneKey, $tillSms);
check($again['status'] === 200 && $again['body']['recorded'] === false && $again['body']['reason'] === 'already_have_it', 'A repeated message was recorded twice: ' . $again['raw']);
$forged = $pass($second['body']['key'], str_replace('Ksh500.00', 'Ksh9,500.00', $tillSms));
check($forged['body']['recorded'] === false, 'A second message for a code replaced the first.');
$same = $pdo->query("SELECT * FROM payment_inbox WHERE mpesa_code = 'SJK2TILL9X'")->fetch(PDO::FETCH_ASSOC);
check($same == $row, 'A repeated message changed the stored payment.');
check((int) $pdo->query('SELECT COUNT(*) FROM payment_inbox')->fetchColumn() === 1, 'A repeated message added a row.');

// an iPhone Shortcut may send a form instead of JSON
$form = curl_init($baseUrl . '?action=payment_inbox_ingest');
curl_setopt_array($form, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => http_build_query(['key' => $phoneKey, 'text' => 'SJK3FORM1A Confirmed.You have received Ksh70.00 from MARY A 0712***678 on 17/11/26 at 9:10 AM New M-PESA balance is Ksh70.00.'])]);
$formAnswer = json_decode((string) curl_exec($form), true);
curl_close($form);
check(($formAnswer['recorded'] ?? false) === true, 'A message sent as a form was not recorded: ' . json_encode($formAnswer));

// field names typed on an iPhone come with a capital first letter (and sometimes a space): they still count
$capitals = $inbox('payment_inbox_ingest', 'POST', ['Key' => $phoneKey, 'Text ' => 'CAP1TALS1A Confirmed.You have received Ksh20.00 from C A on 17/11/26 at 9:12 AM'], []);
check(($capitals['body']['recorded'] ?? false) === true, 'Field names "Key" and "Text " were not understood: ' . $capitals['raw']);
$shouting = $inbox('payment_inbox_ingest', 'POST', ['KEY' => $phoneKey, 'TEXT' => 'CAP1TALS2B Confirmed.You have received Ksh21.00 from C B on 17/11/26 at 9:13 AM'], []);
check(($shouting['body']['recorded'] ?? false) === true, 'Field names in capitals were not understood: ' . $shouting['raw']);
check($inbox('payment_inbox_ingest', 'POST', ['Key' => $phoneKey, 'Message' => 'CAP1TALS3C Confirmed.You have received Ksh22.00 from C C'], [])['status'] === 422, 'A body without a text field was not refused.');
$pdo->exec("DELETE FROM payment_inbox WHERE mpesa_code IN ('CAP1TALS1A', 'CAP1TALS2B')");

// the same code in another shop is that shop's own
$elsewhereKey = $elsewhere['body']['key'];
check($pass($elsewhereKey, str_replace('Ksh500.00', 'Ksh123.00', $tillSms))['body']['recorded'] === true, 'Another shop could not record a code this shop also has.');
check((int) $pdo->query("SELECT amount_cents FROM payment_inbox WHERE mpesa_code = 'SJK2TILL9X' AND shop_id = $otherShopId")->fetchColumn() === 12300, "The other shop's payment is not its own.");

// ---- M-PESA Jumbe: the till's alert to a nominated phone is a payment received; money this phone paid never is ---------
$jumbeAlert = static fn (string $code, string $amount = '60.00') => "$code Confirmed.\nAmount: Ksh$amount.\nPaid to: MAMA MBOGA KUNDI\nTILL: 5678900\nFrom: NK_74\nDate: 2026-11-19 at 18:27:37.\n\nMPESA Jumbe! A public-friendly SMS you can display without exposing personal MPESA information.";
$paidTill = static fn (string $code) => "$code Confirmed. Ksh60.00 paid to MAMA MBOGA KUNDI. on 19/11/26 at 6:27 PM.New M-PESA balance is Ksh940.00. Transaction cost, Ksh0.00.";
$inboxRow = static function (string $code) use ($pdo, $inboxShopId) {
    $stmt = $pdo->prepare('SELECT * FROM payment_inbox WHERE shop_id = ? AND mpesa_code = ?');
    $stmt->execute([$inboxShopId, $code]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
};
$spentCount = static fn () => (int) $pdo->query('SELECT COUNT(*) FROM payment_inbox_spent')->fetchColumn();

$jumbeTaken = $pass($phoneKey, $jumbeAlert('UKJB430TS5'));
check(($jumbeTaken['body']['recorded'] ?? false) === true, 'A Jumbe payment alert was not recorded: ' . $jumbeTaken['raw']);
$jumbeRow = $inboxRow('UKJB430TS5');
check($jumbeRow && (int) $jumbeRow['amount_cents'] === 6000 && $jumbeRow['payer_name'] === 'NK_74' && $jumbeRow['paid_at_text'] === '2026-11-19 18:27:37' && $jumbeRow['received_at'] !== null,
    'The Jumbe payment was not stored as the alert says: ' . json_encode($jumbeRow));

// the customer's own Jumbe receipt (money this phone paid) is dropped, and only its code is remembered
$receipt = $pass($phoneKey, 'UFAL477KQJ Confirmed. You sent: Ksh2,000.00. Received by: Petrol Station Till. TILL: 5678900. Your JumbeID: NK_74. Date: 2026-06-10 at 14:56:38.');
check(($receipt['body']['recorded'] ?? true) === false && ($receipt['body']['reason'] ?? '') === 'not_a_payment', "The customer's Jumbe receipt was taken as a payment: " . $receipt['raw']);
check(!$inboxRow('UFAL477KQJ'), "The customer's Jumbe receipt was stored as a payment.");
$spentRow = $pdo->query("SELECT * FROM payment_inbox_spent WHERE mpesa_code = 'UFAL477KQJ'")->fetch(PDO::FETCH_ASSOC);
check($spentRow && (int) $spentRow['shop_id'] === $inboxShopId && count($spentRow) === 3, 'A code this phone paid out was not remembered (or more than the code was kept): ' . json_encode($spentRow));

// this phone paid a till, and the till's Jumbe alert for the same code reached it too: in either order, no payment
check(($pass($phoneKey, $paidTill('UKJB440PO1'))['body']['reason'] ?? '') === 'not_a_payment', 'A payment this phone made was not dropped.');
$after = $pass($phoneKey, $jumbeAlert('UKJB440PO1'));
check(($after['body']['recorded'] ?? true) === false && !$inboxRow('UKJB440PO1'), 'A Jumbe alert for a code this phone paid out was taken as a payment: ' . $after['raw']);
check(($pass($phoneKey, $jumbeAlert('UKJB440PO2'))['body']['recorded'] ?? false) === true && $inboxRow('UKJB440PO2'), 'The Jumbe alert (arriving first) was not recorded.');
$pass($phoneKey, $paidTill('UKJB440PO2'));
check(!$inboxRow('UKJB440PO2'), 'A Jumbe "payment" was kept after this phone\'s own payment message for the same code arrived.');
check(($pass($phoneKey, $jumbeAlert('UKJB440PO2'))['body']['recorded'] ?? true) === false && !$inboxRow('UKJB440PO2'), 'The same Jumbe alert sent again brought the paid-out code back.');

// ...but a sale that already used the code is never undone, and a till's own "received" message is never taken back
check(($pass($phoneKey, $jumbeAlert('UKJB440PO3'))['body']['recorded'] ?? false) === true, 'Jumbe alert PO3 was not recorded.');
$pdo->prepare("UPDATE payment_inbox SET claimed_sale_id = 'sale-po3', claimed_sale_number = 'S-PO3', claimed_amount_cents = 6000, claimed_at = UTC_TIMESTAMP() WHERE shop_id = ? AND mpesa_code = 'UKJB440PO3'")->execute([$inboxShopId]);
$pass($phoneKey, $paidTill('UKJB440PO3'));
check(($inboxRow('UKJB440PO3')['claimed_sale_id'] ?? null) === 'sale-po3', 'A payment a sale had already used was removed.');
check(($pass($phoneKey, 'UKJB440PO4 Confirmed. on 19/11/26 at 6:30 PM Ksh5.00 received from 254712345678 OWNER TEST. New Account balance is Ksh5.00.')['body']['recorded'] ?? false) === true, 'A till received message was not recorded.');
$pass($phoneKey, $paidTill('UKJB440PO4'));
check($inboxRow('UKJB440PO4') !== false, "A till's own received message was taken back because this phone also paid it (an owner testing their own till).");

// remembered for 7 days only; and one shop's paid-out codes are nothing to another shop
$pdo->exec("UPDATE payment_inbox_spent SET seen_at = UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE mpesa_code = 'UFAL477KQJ'");
check(($pass($phoneKey, $jumbeAlert('UFAL477KQJ'))['body']['recorded'] ?? false) === true, 'A paid-out code older than 7 days still blocked a payment.');
$pass($phoneKey, $paidTill('UKJB440PO5'));
check((int) $pdo->query("SELECT COUNT(*) FROM payment_inbox_spent WHERE mpesa_code = 'UFAL477KQJ'")->fetchColumn() === 0, 'Paid-out codes older than 7 days are not cleared away.');
check(($pass($elsewhereKey, $jumbeAlert('UKJB440PO1'))['body']['recorded'] ?? false) === true, "Another shop's Jumbe alert was blocked by this shop's paid-out code.");
check($spentCount() >= 3, 'Paid-out codes went missing.');
$pdo->exec("DELETE FROM payment_inbox WHERE mpesa_code IN ('UKJB430TS5', 'UKJB440PO3', 'UKJB440PO4', 'UFAL477KQJ', 'UKJB440PO1')");

// every message counts toward the phone's hour; 600 is the most
check($pdo->query('SELECT last_used_at FROM payment_inbox_sources WHERE id = ' . (int) $phoneId)->fetchColumn() !== null, 'The phone was not marked as heard from.');
$pdo->exec('UPDATE payment_inbox_sources SET window_count = 600 WHERE id = ' . (int) $phoneId);
$tooMany = $pass($phoneKey, 'SJK4MANY2B Confirmed.You have received Ksh10.00 from A B on 17/11/26 at 9:11 AM');
check($tooMany['status'] === 429 && $tooMany['body']['reason'] === 'too_many', 'The 601st message in an hour was taken: ' . $tooMany['raw']);
check((int) $pdo->query("SELECT COUNT(*) FROM payment_inbox WHERE mpesa_code = 'SJK4MANY2B'")->fetchColumn() === 0, 'A message over the limit was stored.');
$pdo->exec('UPDATE payment_inbox_sources SET window_started_at = UTC_TIMESTAMP() - INTERVAL 61 MINUTE WHERE id = ' . (int) $phoneId);
check($pass($phoneKey, 'SJK4MANY2B Confirmed.You have received Ksh10.00 from A B on 17/11/26 at 9:11 AM')['body']['recorded'] === true, 'A new hour did not start the count again.');
check((int) $pdo->query('SELECT window_count FROM payment_inbox_sources WHERE id = ' . (int) $phoneId)->fetchColumn() === 1, 'The count of the new hour should be 1.');

// ---- the shop's tills: list and check --------------------------------------------------------------------------------
check($inbox('payment_inbox_list', 'GET', null, [])['status'] === 401 && $inbox('payment_inbox_check', 'GET', null, [], ['code' => 'SJK2TILL9X'])['status'] === 401, 'The inbox was readable with no device key.');
$list = $inbox('payment_inbox_list', 'GET', null, $tillAuth);
check($list['status'] === 200 && $list['body']['phones'] === 5 && array_column($list['body']['payments'], 'code') === ['SJK4MANY2B', 'SJK3FORM1A', 'SJK2TILL9X'], 'The till does not see the payments newest first: ' . $list['raw']);
$first = $list['body']['payments'][2];
check($first['message_received'] === true && $first['amount_cents'] === 50000 && $first['payer_name'] === 'JOHN DOE' && $first['phone_label'] === "Mama's iPhone" && $first['used'] === null
    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $first['received_at']) === 1, 'A listed payment is not described right: ' . json_encode($first));
check(!str_contains($list['raw'], '10,500') && !str_contains($list['raw'], 'npk_'), "The list gives the owner's balance or a key away.");
$otherList = $inbox('payment_inbox_list', 'GET', null, $otherAuth);
check(array_column($otherList['body']['payments'], 'amount_cents') === [12300], "A shop was shown another shop's payments: " . $otherList['raw']);
// older than the asked-for hours: not listed (but still checkable by its code)
$pdo->exec("UPDATE payment_inbox SET received_at = UTC_TIMESTAMP() - INTERVAL 30 HOUR WHERE mpesa_code = 'SJK3FORM1A'");
check(!in_array('SJK3FORM1A', array_column($inbox('payment_inbox_list', 'GET', null, $tillAuth)['body']['payments'], 'code'), true), 'A payment from 30 hours ago is in the last 24 hours.');
check(in_array('SJK3FORM1A', array_column($inbox('payment_inbox_list', 'GET', null, $tillAuth, ['hours' => 48])['body']['payments'], 'code'), true), 'A payment from 30 hours ago is not in the last 48 hours.');

foreach (['', 'short', 'ABCDEFGHIJ', '1234567890', 'SJK2TILL9X!!'] as $notCode) {
    $no = $inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => $notCode]);
    check($no['status'] === 422 && $no['body']['reason'] === 'bad_code', "'$notCode' was taken for an M-Pesa code: " . $no['raw']);
}
$unknown = $inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => 'ZZZ9NEVER1']);
check($unknown['status'] === 200 && $unknown['body']['found'] === false && $unknown['body']['payment'] === null, 'A code nobody has seen was found: ' . $unknown['raw']);
$known = $inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => ' sjk2 till-9x ']);
check($known['body']['found'] === true && $known['body']['code'] === 'SJK2TILL9X' && $known['body']['payment']['amount_cents'] === 50000, 'A code typed in small letters with spaces was not found: ' . $known['raw']);

// ---- using a code on a sale: once ---------------------------------------------------------------------------------------
$saleA = '30000000-0000-4000-8000-0000000000c1';
$saleB = '30000000-0000-4000-8000-0000000000c2';
$saleC = '30000000-0000-4000-8000-0000000000c3';
$claim = static fn (array $headers, string $code, string $saleId, $amount, array $more = []) => $inbox('payment_inbox_claim', 'POST', array_merge(['code' => $code, 'sale_id' => $saleId, 'sale_number' => 'N-' . substr($saleId, -2), 'amount_cents' => $amount], $more), $headers);

check($claim([], 'SJK2TILL9X', $saleA, 50000)['status'] === 401, 'A code was used with no device key.');
foreach ([['SJK2TILL9X', 'not-a-sale', 50000], ['SJK2TILL9X', $saleA, 0], ['SJK2TILL9X', $saleA, -5], ['SJK2TILL9X', $saleA, '50000'], ['SJK2TILL9X', $saleA, 500.5], ['bad', $saleA, 50000]] as [$c, $s, $a]) {
    check($claim($tillAuth, $c, $s, $a)['status'] === 422, 'A malformed claim was not refused: ' . json_encode([$c, $s, $a]));
}
$differs = $claim($tillAuth, 'SJK2TILL9X', $saleA, 45000);
check($differs['status'] === 409 && $differs['body']['reason'] === 'amount_differs' && $differs['body']['amount_cents'] === 50000, 'A sale of another amount took the payment without being told: ' . $differs['raw']);
check($pdo->query("SELECT claimed_sale_id FROM payment_inbox WHERE mpesa_code = 'SJK2TILL9X' AND shop_id = $inboxShopId")->fetchColumn() === null, 'A refused claim was stored.');
$used = $claim($tillAuth, 'sjk2till9x', $saleA, 50000, ['require_message' => true]);
check($used['status'] === 200 && $used['body']['confirmed'] === true && $used['body']['payment']['used']['sale_id'] === $saleA && $used['body']['payment']['used']['sale_number'] === 'N-c1' && $used['body']['payment']['used']['amount_cents'] === 50000, 'The payment could not be used on its sale: ' . $used['raw']);
check($claim($ownerAuth, 'SJK2TILL9X', $saleA, 50000)['status'] === 200, 'The same sale asking again (a lost answer) was refused.');
$second = $claim($ownerAuth, 'SJK2TILL9X', $saleB, 50000);
check($second['status'] === 409 && $second['body']['reason'] === 'used' && $second['body']['sale_number'] === 'N-c1' && str_contains($second['body']['message'], 'N-c1'), 'A code was used on a second sale: ' . $second['raw']);
check($claim($ownerAuth, 'SJK2TILL9X', $saleB, 50000, ['accept_amount' => true])['status'] === 409, '"accept the amount" must not get round a used code.');
$afterUse = $inbox('payment_inbox_check', 'GET', null, $ownerAuth, ['code' => 'SJK2TILL9X'])['body']['payment'];
check($afterUse['used']['sale_id'] === $saleA, 'The code is not shown as used by its sale.');
// the other shop's payment with the same code is untouched and can be used there
$theirs = $inbox('payment_inbox_check', 'GET', null, $otherAuth, ['code' => 'SJK2TILL9X'])['body']['payment'];
check($theirs['amount_cents'] === 12300 && $theirs['used'] === null && $theirs['phone_label'] === "Stranger's phone", "Checking a code another shop also has must show this shop's own payment: " . json_encode($theirs));
check($claim($otherAuth, 'SJK2TILL9X', $saleB, 12300)['status'] === 200, "The other shop's payment was blocked by this shop's sale.");

// the customer paid another amount and the cashier goes ahead knowingly
check($claim($tillAuth, 'SJK4MANY2B', $saleB, 2500)['status'] === 409, 'Ksh 10 was taken for a Ksh 25 sale.');
$accepted = $claim($tillAuth, 'SJK4MANY2B', $saleB, 2500, ['accept_amount' => true]);
check($accepted['status'] === 200 && $accepted['body']['payment']['amount_cents'] === 1000 && $accepted['body']['payment']['used']['amount_cents'] === 2500, 'An accepted difference should record both amounts: ' . $accepted['raw']);

// a code whose message has not arrived
$early = $claim($tillAuth, 'TYP3DCODE1', $saleC, 30000, ['require_message' => true]);
check($early['status'] === 404 && $early['body']['reason'] === 'not_received', 'A code with no message was used by a till that asks for the message: ' . $early['raw']);
check((int) $pdo->query("SELECT COUNT(*) FROM payment_inbox WHERE mpesa_code = 'TYP3DCODE1'")->fetchColumn() === 0, 'A refused claim left a row.');
$typed = $claim($tillAuth, 'TYP3DCODE1', $saleC, 30000);
check($typed['status'] === 200 && $typed['body']['confirmed'] === false && $typed['body']['payment']['message_received'] === false && $typed['body']['payment']['amount_cents'] === null && $typed['body']['payment']['phone_label'] === null, "A typed code was not taken on the cashier's word: " . $typed['raw']);
check($claim($ownerAuth, 'TYP3DCODE1', $saleB, 30000)['status'] === 409, 'A typed code was used on a second sale.');
$listedTyped = $inbox('payment_inbox_list', 'GET', null, $tillAuth)['body']['payments'];
check($listedTyped[0]['code'] === 'TYP3DCODE1' && $listedTyped[0]['message_received'] === false, 'A typed, unconfirmed code is not listed: ' . json_encode($listedTyped[0]));
// ...then its message arrives: the sale is confirmed, and stays that sale's
$late = $pass($phoneKey, 'TYP3DCODE1 Confirmed.You have received Ksh300.00 from LATE PAYER 0700111222 on 17/11/26 at 9:30 AM New M-PESA balance is Ksh1.00.');
check($late['body']['recorded'] === true, 'The message for a typed code was not recorded: ' . $late['raw']);
$confirmed = $inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => 'TYP3DCODE1'])['body']['payment'];
check($confirmed['message_received'] === true && $confirmed['amount_cents'] === 30000 && $confirmed['payer_name'] === 'LATE PAYER' && $confirmed['used']['sale_id'] === $saleC && $confirmed['phone_label'] === "Mama's iPhone", 'A typed code was not confirmed by its message: ' . json_encode($confirmed));

// a message whose amount could not be read
check($pass($phoneKey, 'UNR3ADABL1 Confirmed. You have received money from JOHN DOE on 17/11/26 at 9:40 AM.')['body']['recorded'] === true, 'A received message with no readable amount was dropped.');
$blind = $claim($tillAuth, 'UNR3ADABL1', $saleB, 1000);
check($blind['status'] === 409 && $blind['body']['reason'] === 'amount_unknown', 'A payment of unknown amount was used without a warning: ' . $blind['raw']);
check($claim($tillAuth, 'UNR3ADABL1', '30000000-0000-4000-8000-0000000000c9', 1000, ['accept_amount' => true])['status'] === 200, 'A payment of unknown amount could not be used knowingly.');

// ---- archiving: no longer suggested at checkout, still in the list, the code still usable ----------------------------
$archive = static fn (array $headers, string $code, ?bool $archived = null) => $inbox('payment_inbox_archive', 'POST', $archived === null ? ['code' => $code] : ['code' => $code, 'archived' => $archived], $headers);
check($pass($phoneKey, 'ARCH1VEME1 Confirmed.You have received Ksh40.00 from OLD PAYER 0700000444 on 17/11/26 at 9:45 AM')['body']['recorded'] === true, 'Could not record the payment to archive.');
check($archive([], 'ARCH1VEME1')['status'] === 401, 'A payment was archived with no device key.');
check($archive($tillAuth, 'not a code')['status'] === 422, 'Something that is not a code was archived.');
check($archive($tillAuth, 'N0SUCHC0DE')['status'] === 404, 'A code the shop never received was archived.');
check($archive($otherAuth, 'ARCH1VEME1')['status'] === 404, "Another shop archived this shop's payment.");
$typedOnly = $claim($tillAuth, 'TYP3D0NLY1', '30000000-0000-4000-8000-0000000000ca', 500);
check($typedOnly['status'] === 200 && $archive($tillAuth, 'TYP3D0NLY1')['status'] === 404, 'A code only typed at a till (no message) was archived.');
$beforeArchive = $inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => 'ARCH1VEME1'])['body']['payment'];
check(array_key_exists('archived_at', $beforeArchive) && $beforeArchive['archived_at'] === null, 'A payment that was never archived should say archived_at null: ' . json_encode($beforeArchive));
$archived = $archive($tillAuth, ' arch1 veme1 ');
check($archived['status'] === 200 && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $archived['body']['payment']['archived_at']) === 1, 'The payment was not archived: ' . $archived['raw']);
$firstArchive = $archived['body']['payment']['archived_at'];
$stored = $pdo->query("SELECT archived_by_client_id FROM payment_inbox WHERE mpesa_code = 'ARCH1VEME1'")->fetchColumn();
check((int) $stored === (int) $pdo->query("SELECT id FROM clients WHERE device_id = 'inbox-lite-till'")->fetchColumn(), 'Which device archived it is not kept.');
$pdo->exec("UPDATE payment_inbox SET archived_at = archived_at - INTERVAL 1 HOUR WHERE mpesa_code = 'ARCH1VEME1'");
$again = $archive($ownerAuth, 'ARCH1VEME1');
check($again['status'] === 200 && $again['body']['payment']['archived_at'] !== null && $again['body']['payment']['archived_at'] < $firstArchive, 'Archiving twice should keep the first time: ' . $again['raw']);
check((int) $pdo->query("SELECT archived_by_client_id FROM payment_inbox WHERE mpesa_code = 'ARCH1VEME1'")->fetchColumn() === (int) $stored, 'Archiving twice should keep who did it first.');
$listedArchived = array_values(array_filter($inbox('payment_inbox_list', 'GET', null, $tillAuth)['body']['payments'], static fn ($p) => $p['code'] === 'ARCH1VEME1'));
check(count($listedArchived) === 1 && $listedArchived[0]['archived_at'] !== null, 'An archived payment must stay in the list, marked archived.');
$suggested = $archive($tillAuth, 'ARCH1VEME1', false);
check($suggested['status'] === 200 && $suggested['body']['payment']['archived_at'] === null, 'The payment could not be suggested again: ' . $suggested['raw']);
check($pdo->query("SELECT archived_by_client_id FROM payment_inbox WHERE mpesa_code = 'ARCH1VEME1'")->fetchColumn() === null, 'Suggesting again should forget who archived it.');
check($archive($tillAuth, 'ARCH1VEME1')['status'] === 200, 'Could not archive it again.');
// the code of an archived payment can still be typed and used, once
$usedArchived = $claim($ownerAuth, 'ARCH1VEME1', '30000000-0000-4000-8000-0000000000cb', 4000);
check($usedArchived['status'] === 200 && $usedArchived['body']['payment']['archived_at'] !== null, 'An archived payment could not be used by typing its code: ' . $usedArchived['raw']);
check($claim($tillAuth, 'ARCH1VEME1', '30000000-0000-4000-8000-0000000000cc', 4000)['status'] === 409, 'An archived payment was used twice.');

// two tills, the same code, the same moment: one sale gets it
$race = static function (string $code, int $amount) use ($baseUrl, $tillAuth, $ownerAuth): array {
    $answers = concurrentRequests($baseUrl, [
        ['action' => 'payment_inbox_claim', 'body' => ['code' => $code, 'sale_id' => '40000000-0000-4000-8000-0000000000d1', 'sale_number' => 'RACE-1', 'amount_cents' => $amount], 'headers' => $tillAuth],
        ['action' => 'payment_inbox_claim', 'body' => ['code' => $code, 'sale_id' => '40000000-0000-4000-8000-0000000000d2', 'sale_number' => 'RACE-2', 'amount_cents' => $amount], 'headers' => $ownerAuth],
    ]);
    $statuses = array_column($answers, 'status');
    sort($statuses);
    return $statuses;
};
check($pass($phoneKey, 'RAC3RECVD1 Confirmed.You have received Ksh80.00 from R A on 17/11/26 at 9:50 AM')['body']['recorded'] === true, 'Could not record the payment to race for.');
for ($round = 1; $round <= 5; $round++) {
    $code = 'RAC3TYPED' . $round;
    check($race($code, 8000) === [200, 409], "Two tills both used the typed code $code (or neither did).");
    check((int) $pdo->query("SELECT COUNT(*) FROM payment_inbox WHERE mpesa_code = '$code'")->fetchColumn() === 1, "The typed code $code has more than one row.");
}
check($race('RAC3RECVD1', 8000) === [200, 409], 'Two tills both used the same received payment (or neither did).');

// ---- giving a code back (the sale was cancelled) -----------------------------------------------------------------------
$release = static fn (array $headers, string $code, string $saleId) => $inbox('payment_inbox_release', 'POST', ['code' => $code, 'sale_id' => $saleId], $headers);
check($release([], 'SJK2TILL9X', $saleA)['status'] === 401, 'A code was given back with no device key.');
check($release($tillAuth, 'SJK2TILL9X', $saleB)['body']['released'] === false, 'A sale gave back a code another sale holds.');
check($release($otherAuth, 'SJK4MANY2B', $saleB)['body']['released'] === false, "A shop gave back another shop's code.");
check($release($tillAuth, 'SJK2TILL9X', $saleA)['body']['released'] === true, 'The sale could not give its code back.');
$freed = $inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => 'SJK2TILL9X'])['body']['payment'];
check($freed['used'] === null && $freed['message_received'] === true && $freed['amount_cents'] === 50000, 'A given-back payment should be as it arrived: ' . json_encode($freed));
check($claim($ownerAuth, 'SJK2TILL9X', $saleB, 50000)['status'] === 200, 'A given-back payment could not be used on another sale.');
// a code that was only ever typed is forgotten altogether
check($claim($tillAuth, 'TYP3DCODE2', $saleC, 100)['status'] === 200, 'A typed code could not be used.');
check($release($tillAuth, 'TYP3DCODE2', $saleB)['body']['released'] === false && $release($otherAuth, 'TYP3DCODE2', $saleC)['body']['released'] === false, 'A typed code was given back by a sale or a shop that does not hold it.');
check($inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => 'TYP3DCODE2'])['body']['found'] === true, 'A refused give-back forgot the typed code.');
check($release($tillAuth, 'TYP3DCODE2', $saleC)['body']['released'] === true, 'A typed code could not be given back.');
check($inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => 'TYP3DCODE2'])['body']['found'] === false, 'A typed code that was given back is still remembered.');

// ---- removing a phone -----------------------------------------------------------------------------------------------
$remove = static fn (array $headers, $id, string $who, string $password) => $inbox('payment_inbox_remove_source', 'POST', ['phone_id' => $id, 'user_id' => $who, 'password' => $password], $headers);
$forgetWrongPasswords();
check($remove([], $phoneId, $people['admin']['id'], $people['admin']['password'])['status'] === 401, 'A phone was removed with no device key.');
$no = $remove($tillAuth, $phoneId, $people['cashier']['id'], $people['cashier']['password']);
check($no['status'] === 403 && $no['body']['reason'] === 'not_admin', 'A cashier removed a phone: ' . $no['raw']);
$no = $remove($tillAuth, $phoneId, $people['admin']['id'], 'nope');
check($no['status'] === 403 && $no['body']['reason'] === 'wrong_password', 'A phone was removed with a wrong password: ' . $no['raw']);
check($remove($otherAuth, $phoneId, $stranger['id'], $stranger['password'])['status'] === 404, "Another shop removed this shop's phone.");
check($pass($phoneKey, 'STILLWRKS1 Confirmed.You have received Ksh5.00 from S W on 17/11/26 at 10:00 AM')['body']['recorded'] === true, 'The phone stopped working before it was removed.');
$removed = $remove($tillAuth, $phoneId, $people['admin']['id'], $people['admin']['password']);
check($removed['status'] === 200 && !in_array($phoneId, array_column($removed['body']['phones'], 'id'), true) && count($removed['body']['phones']) === 4, 'The phone was not removed: ' . $removed['raw']);
$dead = $pass($phoneKey, 'AFT3RGONE1 Confirmed.You have received Ksh5.00 from A G on 17/11/26 at 10:01 AM');
check($dead['status'] === 401 && (int) $pdo->query("SELECT COUNT(*) FROM payment_inbox WHERE mpesa_code = 'AFT3RGONE1'")->fetchColumn() === 0, "A removed phone's key still works: " . $dead['raw']);
check($remove($tillAuth, $phoneId, $people['admin']['id'], $people['admin']['password'])['status'] === 404, 'A phone was removed twice.');
// what it passed on before stays, and still says which phone it came from
$kept = $inbox('payment_inbox_check', 'GET', null, $tillAuth, ['code' => 'STILLWRKS1'])['body']['payment'];
check($kept !== null && $kept['phone_label'] === "Mama's iPhone", "A removed phone's earlier payments were lost: " . json_encode($kept));
check($addPhone($tillAuth, $people['admin']['id'], $people['admin']['password'], 'New phone')['status'] === 201, 'A phone could not be set up in the place of the removed one.');

echo "Payment inbox tests passed: phones set up by an administrator only, received payments kept and everything else dropped, a code used on one sale only.\n";
