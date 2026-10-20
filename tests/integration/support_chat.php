<?php

declare(strict_types=1);

// Support chat: replies that quote a message, emoji reactions, and the notification emails
// (shop + device in every internal mail, one thread per ticket).
//
// Needs a platform server whose support email goes to the fake SMTP server:
//   php tests/integration/fake_smtp_server.php plain 2525 /tmp/smtp.jsonl both mailer secret &
//   PLATFORM_SUPPORT_EMAIL_FROM=support@sup.test PLATFORM_SUPPORT_SMTP_HOST=127.0.0.1 \
//   PLATFORM_SUPPORT_SMTP_PORT=2525 PLATFORM_SUPPORT_SMTP_SECURE=none \
//   PLATFORM_SUPPORT_SMTP_USER=mailer PLATFORM_SUPPORT_SMTP_PASS=secret \
//   php -S 127.0.0.1:8081 -t public public/index.php &
//   TEST_PLATFORM_URL=http://127.0.0.1:8081/index.php TEST_SMTP_CAPTURE=/tmp/smtp.jsonl \
//   php tests/integration/support_chat.php

$baseUrl = getenv('TEST_PLATFORM_URL') ?: 'http://127.0.0.1:8081/index.php';
$adminSecret = getenv('PLATFORM_ADMIN_SECRET') ?: 'integration-admin';
$capture = getenv('TEST_SMTP_CAPTURE') ?: '/tmp/smtp.jsonl';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $what . "\n";
    if (!$ok) {
        $failures++;
    }
}

function call(string $baseUrl, string $action, string $method = 'GET', ?array $body = null, array $headers = [], array $query = []): array
{
    $url = $baseUrl . '?' . http_build_query(['action' => $action] + $query);
    $ch = curl_init($url);
    $all = array_merge(['Accept: application/json'], $headers);
    if ($body !== null) {
        $all[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $all, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => $raw];
}

/** Every mail the fake SMTP server has received so far, headers parsed and body decoded. */
function mails(string $capture): array
{
    $out = [];
    foreach (is_file($capture) ? file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $event = json_decode($line, true);
        if (!is_array($event) || ($event['type'] ?? '') !== 'mail') {
            continue;
        }
        [$head, $body] = explode("\r\n\r\n", $event['data'], 2);
        $headers = [];
        foreach (explode("\r\n", preg_replace('/\r\n[ \t]+/', ' ', $head)) as $headerLine) {
            [$name, $value] = array_pad(explode(': ', $headerLine, 2), 2, '');
            $headers[strtolower($name)] = $value;
        }
        $out[] = [
            'to' => $event['to'][0] ?? '',
            'subject' => mb_decode_mimeheader($headers['subject'] ?? ''),
            'headers' => $headers,
            'rawHead' => $head,
            'text' => (string) base64_decode(str_replace("\r\n", '', $body)),
        ];
    }
    return $out;
}

$auth = static fn (string $key): array => ['Authorization: Bearer ' . $key];
$admin = ['X-Admin-Secret: ' . $adminSecret];
$unique = bin2hex(random_bytes(3));

function registerShop(string $baseUrl, string $deviceId, string $label): string
{
    $r = call($baseUrl, 'register_device', 'POST', ['device_id' => $deviceId, 'device_label' => $label, 'registration_secret' => 'secret-' . $deviceId]);
    if ($r['status'] !== 201) {
        throw new RuntimeException('could not register ' . $deviceId . ': ' . $r['raw']);
    }
    return $r['body']['api_key'];
}

// Names go through the database directly (the app sets them through sync).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Platform\\')) {
        require __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    }
});
$pdo = Platform\Core\Database::connection();

$deviceId = "chat-till-$unique";
$key = registerShop($baseUrl, $deviceId, 'Front counter till');
$pdo->prepare("UPDATE shops SET business_name = ? WHERE id = (SELECT shop_id FROM clients WHERE device_id = ?)")->execute(["Mama Njeri's Kiosk $unique", $deviceId]);
$shopId = (int) $pdo->query("SELECT shop_id FROM clients WHERE device_id = '$deviceId'")->fetchColumn();
$otherKey = registerShop($baseUrl, "chat-other-$unique", 'Somebody else');

$before = count(mails($capture));

// --- a new ticket: one mail to support, one to the shop's own address, each the root of its thread
$open = call($baseUrl, 'support_open', 'POST', [
    'subject' => 'Receipt printer offline',
    'message' => 'The printer shows offline since morning.',
    'email' => 'owner@example.com',
], $auth($key));
check($open['status'] === 201, 'a shop can open a ticket');
$ticketId = (int) $open['body']['ticket_id'];
$sent = array_slice(mails($capture), $before);
check(count($sent) === 2, 'opening a ticket sends exactly two mails (support + the shop): ' . count($sent));
[$internal, $customer] = [$sent[1] ?? null, $sent[0] ?? null];
if (($sent[0]['to'] ?? '') === 'support@sup.test') {
    [$internal, $customer] = [$sent[0], $sent[1]];
}
check(($internal['to'] ?? '') === 'support@sup.test', 'one mail goes to the support mailbox');
check(($customer['to'] ?? '') === 'owner@example.com', 'one mail goes to the address the shop gave');
check(($internal['subject'] ?? '') === "[#$ticketId] Mama Njeri's Kiosk $unique - Receipt printer offline",
    'the support mail subject names the shop and the ticket: ' . ($internal['subject'] ?? ''));
check(str_contains($internal['text'] ?? '', "Shop:    Mama Njeri's Kiosk $unique (shop #$shopId)"), 'the support mail names the shop');
check(str_contains($internal['text'] ?? '', "Device:  Front counter till - ID $deviceId"), 'the support mail carries the device ID and label');
check(str_contains($internal['text'] ?? '', 'The printer shows offline since morning.'), 'the support mail carries the message');
check(str_contains($internal['text'] ?? '', 'owner@example.com'), 'the support mail shows the shop\'s contact email');
check(($internal['headers']['message-id'] ?? '') === "<nexapos-ticket-$ticketId@sup.test>", 'the first support mail is the thread root: ' . ($internal['headers']['message-id'] ?? ''));
check(!isset($internal['headers']['in-reply-to']), 'the root mail refers to nothing');
check(($customer['subject'] ?? '') === "NexaPOS support ticket #$ticketId: Receipt printer offline", 'the shop\'s mail has its own stable subject');
check(($customer['headers']['message-id'] ?? '') === "<nexapos-ticket-$ticketId-customer@sup.test>", 'the shop\'s mail is the root of the shop\'s thread');
check(!str_contains($customer['text'] ?? '', $deviceId), 'the shop\'s own mail does not carry device internals');

// --- the shop writes again: same subject, same thread
$first = call($baseUrl, 'support_thread', 'GET', null, $auth($key), ['ticket_id' => $ticketId]);
$firstMessageId = (int) $first['body']['messages'][0]['id'];
$reply = call($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Still offline after a restart.', 'reply_to_id' => $firstMessageId], $auth($key));
check($reply['status'] === 200 && ($reply['body']['message_id'] ?? 0) > $firstMessageId, 'a reply returns its message id');
$replyId = (int) $reply['body']['message_id'];
$sent = array_slice(mails($capture), $before + 2);
$again = $sent[0] ?? [];
check(count($sent) === 1 && ($again['to'] ?? '') === 'support@sup.test', 'a reply sends one mail, to support');
check(($again['subject'] ?? '') === ($internal['subject'] ?? 'x'), 'the reply mail has the SAME subject, so it stays in one conversation');
check(($again['headers']['in-reply-to'] ?? '') === "<nexapos-ticket-$ticketId@sup.test>" && ($again['headers']['references'] ?? '') === "<nexapos-ticket-$ticketId@sup.test>",
    'the reply mail points at the thread root');
check(($again['headers']['message-id'] ?? '') === "<nexapos-ticket-$ticketId-m$replyId@sup.test>", 'the reply mail has its own id');
check(str_contains($again['text'] ?? '', "ID $deviceId") && str_contains($again['text'] ?? '', 'Still offline after a restart.'), 'the reply mail names the device and carries the text');

// --- support answers: the shop's mail joins the shop's thread
$answer = call($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Please check the paper roll.'], $admin);
check($answer['status'] === 200 && ($answer['body']['message_id'] ?? 0) > $replyId, 'the vendor can reply');
$answerId = (int) $answer['body']['message_id'];
$sent = array_slice(mails($capture), $before + 3);
$toShop = $sent[0] ?? [];
check(count($sent) === 1 && ($toShop['to'] ?? '') === 'owner@example.com', 'the vendor reply goes to the shop\'s address');
check(($toShop['subject'] ?? '') === ($customer['subject'] ?? 'x'), 'it has the shop thread\'s subject');
check(($toShop['headers']['in-reply-to'] ?? '') === "<nexapos-ticket-$ticketId-customer@sup.test>", 'and points at the shop thread\'s root');

// Delivery is the server's successful reply; reading needs a separate,
// authenticated acknowledgement from an open shop conversation.
$beforeRead = array_column(call($baseUrl, 'admin_support_thread', 'GET', null, $admin, ['ticket_id' => $ticketId])['body']['messages'], null, 'id');
check(array_key_exists('read_at', $beforeRead[$answerId]) && $beforeRead[$answerId]['read_at'] === null,
    'the admin reply is delivered but not read immediately after sending');
call($baseUrl, 'support_list', 'GET', null, $auth($key));
call($baseUrl, 'support_thread', 'GET', null, $auth($key), ['ticket_id' => $ticketId]);
$stillUnread = array_column(call($baseUrl, 'admin_support_thread', 'GET', null, $admin, ['ticket_id' => $ticketId])['body']['messages'], null, 'id');
check($stillUnread[$answerId]['read_at'] === null, 'listing/fetching alone does not claim the reply was viewed');
check(call($baseUrl, 'support_mark_read', 'POST', ['ticket_id' => $ticketId, 'through_message_id' => $answerId])['status'] === 401,
    'a read receipt needs a device API key');
check(call($baseUrl, 'support_mark_read', 'POST', ['ticket_id' => $ticketId, 'through_message_id' => $answerId], $auth($otherKey))['status'] === 404,
    'another shop cannot mark this ticket read');
check(call($baseUrl, 'support_mark_read', 'POST', ['ticket_id' => $ticketId, 'through_message_id' => $replyId], $auth($key))['status'] === 404,
    'the shop cannot mark its own message as a support reply');
$markedRead = call($baseUrl, 'support_mark_read', 'POST', ['ticket_id' => $ticketId, 'through_message_id' => $answerId], $auth($key));
check($markedRead['status'] === 200 && ($markedRead['body']['marked'] ?? null) === 1,
    'opening the conversation acknowledges the support reply once');
$afterRead = array_column(call($baseUrl, 'admin_support_thread', 'GET', null, $admin, ['ticket_id' => $ticketId])['body']['messages'], null, 'id');
check(is_string($afterRead[$answerId]['read_at']) && $afterRead[$answerId]['read_at'] !== '',
    'the dashboard receives a read timestamp');
check((call($baseUrl, 'support_mark_read', 'POST', ['ticket_id' => $ticketId, 'through_message_id' => $answerId], $auth($key))['body']['marked'] ?? null) === 0,
    'repeated read receipts preserve the original timestamp');

// --- hostile text cannot add headers to a mail
$evil = call($baseUrl, 'support_open', 'POST', ['subject' => "Hello\r\nBcc: attacker@example.com", 'message' => 'x', 'email' => 'owner@example.com'], $auth($key));
check($evil['status'] === 201, 'a subject with line breaks is accepted');
$pdo->prepare('UPDATE shops SET business_name = ? WHERE id = ?')->execute(["Shop\r\nBcc: attacker2@example.com", $shopId]);
call($baseUrl, 'support_reply', 'POST', ['ticket_id' => (int) $evil['body']['ticket_id'], 'message' => 'again'], $auth($key));
$all = mails($capture);
$injected = array_filter($all, static fn (array $m) => stripos($m['rawHead'], "\nBcc:") !== false || isset($m['headers']['bcc']));
check(count($injected) === 0, 'no mail got an injected Bcc header');
$evilTicketId = (int) $evil['body']['ticket_id'];
$evilMails = array_filter($all, static fn (array $m) => str_starts_with($m['subject'], "[#$evilTicketId] ") && $m['to'] === 'support@sup.test');
check(count($evilMails) === 2, 'hostile text is cleaned up, not a reason to drop the notification (open + reply both arrived): ' . count($evilMails));
check(count(array_filter($evilMails, static fn (array $m) => str_ends_with($m['subject'], ' - Hello Bcc: attacker@example.com'))) === 2, 'the line break became a space in the subject');
$pdo->prepare('UPDATE shops SET business_name = ? WHERE id = ?')->execute(["Mama Njeri's Kiosk $unique", $shopId]);

// --- the thread: quoting
$thread = call($baseUrl, 'support_thread', 'GET', null, $auth($key), ['ticket_id' => $ticketId])['body']['messages'];
$byId = array_column($thread, null, 'id');
check(($byId[$replyId]['reply_to_id'] ?? null) === $firstMessageId && ($byId[$replyId]['reply_to']['snippet'] ?? '') === 'The printer shows offline since morning.'
    && ($byId[$replyId]['reply_to']['sender'] ?? '') === 'customer', 'a reply carries the message it quotes');
check(array_key_exists('reply_to', $byId[$firstMessageId]) && $byId[$firstMessageId]['reply_to'] === null && $byId[$firstMessageId]['reply_to_id'] === null
    && ($byId[$firstMessageId]['reactions'] ?? 'x') === [], 'a plain message quotes nothing and has no reactions');
$otherTicket = (int) call($baseUrl, 'support_open', 'POST', ['subject' => 'Another one', 'message' => 'hello'], $auth($otherKey))['body']['ticket_id'];
check(call($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'x', 'reply_to_id' => 999999999], $auth($key))['status'] === 422,
    'quoting a message that is not in this ticket is refused');
$otherMessage = (int) call($baseUrl, 'support_thread', 'GET', null, $auth($otherKey), ['ticket_id' => $otherTicket])['body']['messages'][0]['id'];
check(call($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'x', 'reply_to_id' => $otherMessage], $auth($key))['status'] === 422,
    'quoting another shop\'s message is refused');
$adminReply = call($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Quoting you.', 'reply_to_id' => $replyId], $admin);
check($adminReply['status'] === 200, 'the vendor can quote a message too');

// --- reactions
$heart = "\u{2764}\u{FE0F}";
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => "\u{1F44D}"], $auth($key))['status'] === 200, 'a shop can react');
check(call($baseUrl, 'admin_support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => "\u{2764}"], $admin)['status'] === 200, 'the vendor can react (with or without the variation selector)');
$read = static fn (string $who) => array_column($who === 'admin'
    ? call($baseUrl, 'admin_support_thread', 'GET', null, $admin, ['ticket_id' => $ticketId])['body']['messages']
    : call($baseUrl, 'support_thread', 'GET', null, $auth($key), ['ticket_id' => $ticketId])['body']['messages'], null, 'id');
foreach (['shop' => 'shop', 'vendor' => 'admin'] as $label => $who) {
    $reactions = $read($who)[$answerId]['reactions'] ?? [];
    $map = array_column($reactions, 'emoji', 'sender');
    check(($map['customer'] ?? '') === "\u{1F44D}" && ($map['support'] ?? '') === $heart, "the $label sees both reactions (heart stored with its variation selector)");
}
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => "\u{1F602}"], $auth($key))['status'] === 200, 'changing a reaction works');
$map = array_column($read('shop')[$answerId]['reactions'], 'emoji', 'sender');
check($map['customer'] === "\u{1F602}" && count($map) === 2, 'a side has one reaction per message: the new one replaced the old');
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => ''], $auth($key))['status'] === 200, 'an empty emoji removes the reaction');
$map = array_column($read('shop')[$answerId]['reactions'], 'emoji', 'sender');
check(!isset($map['customer']) && ($map['support'] ?? '') === $heart, 'only the shop\'s own reaction was removed');
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => "\u{1F4A9}"], $auth($key))['status'] === 422, 'an emoji outside the set is refused');
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => 'x<script>'], $auth($key))['status'] === 422, 'text is refused as a reaction');
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $otherMessage, 'emoji' => "\u{1F44D}"], $auth($key))['status'] === 404, 'a reaction on a message of another ticket is refused');
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => "\u{1F44D}"], $auth($otherKey))['status'] === 404, 'another shop cannot react to this ticket');
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => "\u{1F44D}"])['status'] === 401, 'reacting needs an API key');
check(call($baseUrl, 'admin_support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $answerId, 'emoji' => "\u{1F44D}"])['status'] === 401, 'the vendor reaction needs the admin secret');
$status = call($baseUrl, 'support_thread', 'GET', null, $auth($key), ['ticket_id' => $ticketId])['body']['ticket']['status'];
check($status === 'pending', 'a reaction does not reopen or change the ticket (still "pending", the vendor spoke last): ' . $status);

// --- what the vendor sees about devices; what the shop does not
$adminThread = call($baseUrl, 'admin_support_thread', 'GET', null, $admin, ['ticket_id' => $ticketId])['body'];
check(($adminThread['ticket']['opened_by_device_id'] ?? '') === $deviceId && ($adminThread['ticket']['opened_by_device_label'] ?? '') === 'Front counter till', 'the vendor sees which device opened the ticket');
$adminMessages = array_column($adminThread['messages'], null, 'id');
check(($adminMessages[$replyId]['device_id'] ?? '') === $deviceId && array_key_exists('device_id', $adminMessages[$answerId]) && $adminMessages[$answerId]['device_id'] === null,
    'the vendor sees which device wrote each shop message (and none for its own)');
$shopMessage = $read('shop')[$replyId];
check(!array_key_exists('device_id', $shopMessage) && !array_key_exists('device_label', $shopMessage), 'the shop\'s own view carries no device fields');

echo $failures === 0 ? "\nAll support chat checks passed.\n" : "\n$failures support chat check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
