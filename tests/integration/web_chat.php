<?php

declare(strict_types=1);

// Support chat on the public website, a mandatory email address on every ticket, and the copy of
// every support reply that is emailed to the client.
//
// Same setup as support_chat.php (fake SMTP server + a platform server that mails through it):
//   TEST_PLATFORM_URL=http://127.0.0.1:8081/index.php TEST_SMTP_CAPTURE=/tmp/smtp.jsonl \
//   php tests/integration/web_chat.php
// Website test chats use @webchat.test addresses and are deleted again at the start of each run
// (the per-address limits would otherwise count the previous run).

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
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $all, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawBody = substr((string) $raw, $headerSize);
    return ['status' => $status, 'body' => json_decode($rawBody, true), 'raw' => $rawBody, 'head' => substr((string) $raw, 0, $headerSize)];
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
            'text' => (string) base64_decode(str_replace("\r\n", '', $body)),
        ];
    }
    return $out;
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Platform\\')) {
        require __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    }
});
$pdo = Platform\Core\Database::connection();
$pdo->exec("DELETE FROM support_tickets WHERE customer_email LIKE '%@webchat.test'");
// chats started by hand against this database must not count towards this run's per-address limits
$pdo->exec("UPDATE support_messages SET source_ip_hash = 'before-this-run' WHERE source_ip_hash IS NOT NULL");

$admin = ['X-Admin-Secret: ' . $adminSecret];
$auth = static fn (string $key): array => ['Authorization: Bearer ' . $key];
$tokenHeader = static fn (string $token): array => ['X-Chat-Token: ' . $token];
$unique = bin2hex(random_bytes(3));
$visitorEmail = "visitor-$unique@webchat.test";
$start = static fn (array $body) => call($baseUrl, 'web_chat_start', 'POST', $body);

// --- starting a chat: the email address is mandatory and must be a real-looking one
$before = count(mails($capture));
foreach ([
    'no email' => ['message' => 'Hello there'],
    'blank email' => ['email' => '  ', 'message' => 'Hello there'],
    'text instead of an email' => ['email' => 'not-an-email', 'message' => 'Hello there'],
    'an address without a domain dot' => ['email' => 'someone@localhost', 'message' => 'Hello there'],
    'a header-injection attempt' => ['email' => "a@b.co\r\nBcc: x@y.zz", 'message' => 'Hello there'],
] as $what => $body) {
    $r = $start($body);
    check($r['status'] === 422 && str_contains((string) ($r['body']['message'] ?? ''), 'email'), "starting a chat with $what is refused");
}
check($start(['email' => $visitorEmail, 'message' => 'x'])['status'] === 422, 'a one-character message is refused');
check($start(['email' => $visitorEmail, 'message' => str_repeat('a', 2001)])['status'] === 422, 'a message over 2000 characters is refused');
check($start(['email' => $visitorEmail, 'message' => "\x01\x02 \x03"])['status'] === 422, 'a message of only control characters is refused');
$honey = $start(['email' => $visitorEmail, 'message' => 'Hello bot', 'website' => 'http://spam.example']);
check($honey['status'] === 201 && ($honey['body']['ticket_id'] ?? null) === 0, 'a filled-in honeypot looks like a success to the bot');
check((int) $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE customer_email = '$visitorEmail'")->fetchColumn() === 0, 'but nothing was stored');
check(count(mails($capture)) === $before, 'and nothing was emailed');

$first = $start(['name' => "Wanjiru\r\nBcc: evil@example.com", 'email' => $visitorEmail, 'message' => "Does NexaPOS run on Windows 7?\nWe have two old PCs."]);
check($first['status'] === 201 && preg_match('/^[a-f0-9]{64}$/', (string) ($first['body']['token'] ?? '')) === 1, 'a chat starts and hands the browser a secret token');
$ticketId = (int) $first['body']['ticket_id'];
$token = (string) $first['body']['token'];
$row = $pdo->query("SELECT * FROM support_tickets WHERE id = $ticketId")->fetch(PDO::FETCH_ASSOC);
check($row['shop_id'] === null && $row['opened_by_client_id'] === null && $row['channel'] === 'website' && $row['customer_email'] === $visitorEmail,
    'it is a website ticket with no shop and no device');
check($row['visitor_token_hash'] === hash('sha256', $token) && !str_contains(json_encode($row), $token), 'only a hash of the token is stored');
check($row['visitor_name'] === 'Wanjiru Bcc: evil@example.com', 'the name is one clean line: ' . $row['visitor_name']);
check($row['subject'] === 'Does NexaPOS run on Windows 7? We have two old PCs.', 'the subject comes from the first message: ' . $row['subject']);

$sent = array_slice(mails($capture), $before);
check(count($sent) === 2, 'starting a chat sends two mails (support + the visitor): ' . count($sent));
$internal = null;
$copy = null;
foreach ($sent as $mail) {
    if ($mail['to'] === 'support@sup.test') {
        $internal = $mail;
    } elseif ($mail['to'] === $visitorEmail) {
        $copy = $mail;
    }
}
check($internal !== null && $copy !== null, 'one mail goes to the support mailbox, one to the visitor');
check(($internal['subject'] ?? '') === "[#$ticketId] Website visitor (Wanjiru Bcc: evil@example.com) - Does NexaPOS run on Windows 7? We have two old PCs.",
    'the support mail says it is a website visitor: ' . ($internal['subject'] ?? ''));
check(str_contains($internal['text'] ?? '', 'From:    Website visitor - Wanjiru') && str_contains($internal['text'] ?? '', "Contact: $visitorEmail")
    && str_contains($internal['text'] ?? '', 'We have two old PCs.') && !str_contains($internal['text'] ?? '', 'Shop:'),
    'the support mail names the visitor and their email, and the message');
check(($internal['headers']['message-id'] ?? '') === "<nexapos-ticket-$ticketId@sup.test>", 'it is the root of the support thread');
check(($copy['subject'] ?? '') === "NexaPOS support ticket #$ticketId: Does NexaPOS run on Windows 7? We have two old PCs."
    && str_contains($copy['text'] ?? '', 'We have two old PCs.') && str_contains($copy['text'] ?? '', 'copy of every reply'),
    'the visitor gets an acknowledgement with their message');
check(($copy['headers']['message-id'] ?? '') === "<nexapos-ticket-$ticketId-customer@sup.test>", 'which is the root of their own thread');

// --- reading and writing the chat needs the token
$thread = static fn (string $tok, array $query = []) => call($baseUrl, 'web_chat_thread', 'GET', null, $tok === '' ? [] : ['X-Chat-Token: ' . $tok], ['ticket_id' => $ticketId] + $query);
$view = $thread($token);
check($view['status'] === 200 && count($view['body']['messages']) === 1 && $view['body']['messages'][0]['sender'] === 'customer', 'the visitor can read their chat');
check(!array_key_exists('customer_email', $view['body']['ticket']) && !array_key_exists('read_at', $view['body']['messages'][0]) && !array_key_exists('device_id', $view['body']['messages'][0]),
    'it carries no email, read marker or device fields');
check($thread('')['status'] === 404, 'no token: not found');
check($thread(str_repeat('0', 64))['status'] === 404, 'a wrong token: not found');
check($thread('short')['status'] === 404, 'a malformed token: not found');

$sendMessage = static fn (string $tok, array $body) => call($baseUrl, 'web_chat_send', 'POST', $body + ['ticket_id' => $ticketId], $tok === '' ? [] : ['X-Chat-Token: ' . $tok]);
check($sendMessage('', ['message' => 'hi'])['status'] === 404 && $sendMessage(str_repeat('f', 64), ['message' => 'hi'])['status'] === 404, 'sending needs the token too');
check($sendMessage($token, ['message' => '   '])['status'] === 422, 'an empty message is refused');
check($sendMessage($token, ['message' => str_repeat('b', 2001)])['status'] === 422, 'a message over 2000 characters is refused');
$before = count(mails($capture));
$follow = $sendMessage($token, ['message' => 'Sorry, one is 32-bit.']);
check($follow['status'] === 200 && ($follow['body']['message_id'] ?? 0) > 0, 'the visitor can write again');
$followId = (int) $follow['body']['message_id'];
$sent = array_slice(mails($capture), $before);
check(count($sent) === 1 && $sent[0]['to'] === 'support@sup.test' && ($sent[0]['subject'] ?? '') === ($internal['subject'] ?? 'x')
    && ($sent[0]['headers']['in-reply-to'] ?? '') === "<nexapos-ticket-$ticketId@sup.test>", 'the follow-up joins the same support thread');
check(array_key_exists('reply_to_id', $follow['body']) === false, 'sending returns just the new message id');
$quoted = $sendMessage($token, ['message' => 'And one more thing', 'reply_to_id' => 999999999]);
check($quoted['status'] === 422, 'quoting a message that is not in this chat is refused');

// --- the vendor sees it like any ticket, labelled as a website visitor
$list = call($baseUrl, 'admin_list_support_tickets', 'GET', null, $admin)['body']['tickets'];
$listed = array_values(array_filter($list, static fn (array $t) => $t['id'] === $ticketId))[0] ?? null;
check($listed !== null && $listed['channel'] === 'website' && $listed['business_name'] === null && $listed['shop_id'] === null
    && $listed['customer_email'] === $visitorEmail && $listed['status'] === 'open' && (int) $listed['message_count'] === 2,
    'the dashboard list includes the website chat (no shop), with the visitor\'s email');
$adminThread = call($baseUrl, 'admin_support_thread', 'GET', null, $admin, ['ticket_id' => $ticketId])['body'];
check(($adminThread['ticket']['channel'] ?? '') === 'website' && ($adminThread['ticket']['visitor_name'] ?? '') === 'Wanjiru Bcc: evil@example.com'
    && array_key_exists('opened_by_device_id', $adminThread['ticket']) && $adminThread['ticket']['opened_by_device_id'] === null && count($adminThread['messages']) === 2,
    'the dashboard thread says it is a website visitor and has no device');

// --- every support reply is copied to the client's email
$before = count(mails($capture));
$answer = call($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Yes - 1.0.74 has a Windows 7/8 edition. Which PCs?', 'reply_to_id' => $followId], $admin);
check($answer['status'] === 200 && ($answer['body']['emailed'] ?? null) === true, 'the vendor can reply to the website chat, and is told the copy was emailed');
$answerId = (int) $answer['body']['message_id'];
$sent = array_slice(mails($capture), $before);
$toVisitor = $sent[0] ?? [];
check(count($sent) === 1 && ($toVisitor['to'] ?? '') === $visitorEmail, 'the reply is also emailed to the visitor: ' . count($sent));
check(($toVisitor['subject'] ?? '') === ($copy['subject'] ?? 'x') && ($toVisitor['headers']['in-reply-to'] ?? '') === "<nexapos-ticket-$ticketId-customer@sup.test>",
    'in the visitor\'s own thread');
check(str_contains($toVisitor['text'] ?? '', 'Yes - 1.0.74 has a Windows 7/8 edition. Which PCs?') && str_contains($toVisitor['text'] ?? '', 'https://nexapos.cc'),
    'the email carries the full reply and a way back to the chat');
$view = $thread($token, ['mark_read' => '0'])['body'];
$answered = array_column($view['messages'], null, 'id')[$answerId] ?? [];
check(($view['ticket']['status'] ?? '') === 'pending' && ($answered['body'] ?? '') === 'Yes - 1.0.74 has a Windows 7/8 edition. Which PCs?'
    && ($answered['reply_to']['snippet'] ?? '') === 'Sorry, one is 32-bit.', 'the visitor sees the reply, with the message it quotes, and the chat is "pending"');
$dash = static fn () => array_column(call($baseUrl, 'admin_support_thread', 'GET', null, $admin, ['ticket_id' => $ticketId])['body']['messages'], null, 'id');
check($dash()[$answerId]['read_at'] === null, 'delivered but not read while the visitor has not opened the chat');
$thread($token);
check($dash()[$answerId]['read_at'] === null, 'merely fetching the chat does not claim the reply was read');
$thread($token, ['mark_read' => '1']);
check(is_string($dash()[$answerId]['read_at']), 'an open chat window marks the reply as read');

// reactions and photos from support reach the visitor
call($baseUrl, 'admin_support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $followId, 'emoji' => "\u{1F44D}"], $admin);
$reacted = array_column($thread($token)['body']['messages'], null, 'id')[$followId]['reactions'] ?? [];
check(count($reacted) === 1 && $reacted[0]['sender'] === 'support' && $reacted[0]['emoji'] === "\u{1F44D}", 'the vendor\'s reaction shows up in the visitor\'s chat');
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$before = count(mails($capture));
$withPhoto = call($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'Here is the installer screen.', 'attachments' => [['data' => $png]]], $admin);
check($withPhoto['status'] === 200, 'the vendor can attach a photo to a reply');
$sent = array_slice(mails($capture), $before);
check(count($sent) === 1 && str_contains($sent[0]['text'] ?? '', '(1 photo attached - open the chat on nexapos.cc to see it.)'), 'the emailed copy says a photo is attached');
$photoMessage = array_column($thread($token)['body']['messages'], null, 'id')[(int) $withPhoto['body']['message_id']] ?? [];
$attachmentId = (int) ($photoMessage['attachments'][0]['id'] ?? 0);
check($attachmentId > 0, 'the visitor\'s chat lists the photo');
$image = call($baseUrl, 'web_chat_attachment', 'GET', null, $tokenHeader($token), ['ticket_id' => $ticketId, 'id' => $attachmentId]);
check($image['status'] === 200 && str_contains($image['head'], 'image/png') && strlen($image['raw']) > 20 && str_contains(strtolower($image['head']), 'nosniff'), 'the photo can be fetched with the token');
check(call($baseUrl, 'web_chat_attachment', 'GET', null, $tokenHeader(str_repeat('a', 64)), ['ticket_id' => $ticketId, 'id' => $attachmentId])['status'] === 404
    && call($baseUrl, 'web_chat_attachment', 'GET', null, [], ['ticket_id' => $ticketId, 'id' => $attachmentId])['status'] === 404, 'but not without it');

// another visitor's token opens nothing of this chat; a closed chat reopens when the visitor writes
$other = $start(['email' => "other-$unique@webchat.test", 'message' => 'A different visitor']);
check($other['status'] === 201 && call($baseUrl, 'web_chat_thread', 'GET', null, $tokenHeader($other['body']['token']), ['ticket_id' => $ticketId])['status'] === 404,
    'another visitor\'s token cannot read this chat');
check(call($baseUrl, 'web_chat_send', 'POST', ['ticket_id' => $ticketId, 'message' => 'hijack'], $tokenHeader($other['body']['token']))['status'] === 404,
    'nor write to it');
call($baseUrl, 'admin_close_support_ticket', 'POST', ['ticket_id' => $ticketId], $admin);
check(($thread($token)['body']['ticket']['status'] ?? '') === 'closed', 'a resolved chat says so to the visitor');
$sendMessage($token, ['message' => 'One more question']);
check(($thread($token)['body']['ticket']['status'] ?? '') === 'open', 'writing to a resolved chat reopens it');

// --- the visitor can send photos (a photo alone is a message too)
$before = count(mails($capture));
$photoStart = $start(['email' => "photo-$unique@webchat.test", 'message' => 'Here is my problem', 'attachments' => [['data' => $png]]]);
check($photoStart['status'] === 201, 'a chat can start with a photo');
$photoTicket = (int) $photoStart['body']['ticket_id'];
$photoToken = (string) $photoStart['body']['token'];
$photoThread = static fn () => call($baseUrl, 'web_chat_thread', 'GET', null, $tokenHeader($photoToken), ['ticket_id' => $photoTicket])['body']['messages'];
$ownPhotoId = (int) ($photoThread()[0]['attachments'][0]['id'] ?? 0);
check($ownPhotoId > 0, 'the visitor\'s own message lists its photo');
$ownImage = call($baseUrl, 'web_chat_attachment', 'GET', null, $tokenHeader($photoToken), ['ticket_id' => $photoTicket, 'id' => $ownPhotoId]);
check($ownImage['status'] === 200 && str_contains($ownImage['head'], 'image/png'), 'and can fetch it back');
$vendorImage = call($baseUrl, 'admin_support_attachment', 'GET', null, $admin, ['id' => $ownPhotoId]);
check($vendorImage['status'] === 200 && str_contains($vendorImage['head'], 'image/png'), 'the vendor can open it from the dashboard');
$sent = array_slice(mails($capture), $before);
$photoInternal = array_values(array_filter($sent, static fn (array $m) => $m['to'] === 'support@sup.test'))[0] ?? [];
check(str_contains($photoInternal['text'] ?? '', 'Photos:  1 attached'), 'the support mail says a photo is attached');
$photoOnly = call($baseUrl, 'web_chat_send', 'POST', ['ticket_id' => $photoTicket, 'message' => '', 'attachments' => [['data' => $png], ['data' => $png]]], $tokenHeader($photoToken));
check($photoOnly['status'] === 200, 'a message may be photos only');
$last = array_values(array_filter($photoThread(), static fn (array $m) => (int) $m['id'] === (int) $photoOnly['body']['message_id']))[0] ?? [];
check(($last['body'] ?? 'x') === '' && count($last['attachments'] ?? []) === 2, 'with both photos in it');
$photoOnlyStart = $start(['email' => "photoonly-$unique@webchat.test", 'message' => '', 'attachments' => [['data' => $png]]]);
check($photoOnlyStart['status'] === 201 && $pdo->query('SELECT subject FROM support_tickets WHERE id = ' . (int) $photoOnlyStart['body']['ticket_id'])->fetchColumn() === 'Website chat (photo)',
    'a chat may start with only a photo');
$badSend = static fn (array $attachments) => call($baseUrl, 'web_chat_send', 'POST', ['ticket_id' => $photoTicket, 'message' => 'x', 'attachments' => $attachments], $tokenHeader($photoToken))['status'];
check($badSend([['data' => base64_encode('this is not an image')]]) === 422, 'something that is not an image is refused');
check($badSend(array_fill(0, 5, ['data' => $png])) === 422, 'more than four photos in one message are refused');
check($badSend([['data' => str_repeat('A', 2800001)]]) === 422, 'an oversized photo is refused');
check(call($baseUrl, 'web_chat_send', 'POST', ['ticket_id' => $photoTicket, 'message' => 'x', 'attachments' => 'oops'], $tokenHeader($photoToken))['status'] === 422, 'photos that are not a list are refused');
check(call($baseUrl, 'web_chat_attachment', 'GET', null, $tokenHeader($token), ['ticket_id' => $photoTicket, 'id' => $ownPhotoId])['status'] === 404,
    'another visitor\'s token cannot fetch these photos');
check($start(['email' => "empty-$unique@webchat.test", 'message' => ' '])['status'] === 422, 'no text and no photo is still refused');
// --- a website chat is invisible to every shop and to the app endpoints
$registered =call($baseUrl, 'register_device', 'POST', ['device_id' => "webchat-till-$unique", 'device_label' => 'Till', 'registration_secret' => "secret-$unique"]);
$shopKey = (string) ($registered['body']['api_key'] ?? '');
check($shopKey !== '', 'a shop device registers');
check(call($baseUrl, 'support_thread', 'GET', null, $auth($shopKey), ['ticket_id' => $ticketId])['status'] === 404, 'a shop cannot open a website chat through the app route');
check(call($baseUrl, 'support_reply', 'POST', ['ticket_id' => $ticketId, 'message' => 'x'], $auth($shopKey))['status'] === 404, 'nor reply to it');
check(call($baseUrl, 'support_react', 'POST', ['ticket_id' => $ticketId, 'message_id' => $followId, 'emoji' => "\u{1F44D}"], $auth($shopKey))['status'] === 404, 'nor react to it');
check(call($baseUrl, 'support_list', 'GET', null, $auth($shopKey))['body']['tickets'] === [], 'and its ticket list does not include it');
check(call($baseUrl, 'support_attachment', 'GET', null, $auth($shopKey), ['id' => $attachmentId])['status'] === 404, 'nor the photos in it');
check(call($baseUrl, 'web_chat_thread', 'GET', null, $auth($shopKey), ['ticket_id' => $ticketId])['status'] === 404, 'a device key is not a chat token');

// --- an email address is mandatory for app tickets too
$mandatory = static fn (array $body, string $key) => call($baseUrl, 'support_open', 'POST', ['subject' => 'Printer problem', 'message' => 'It will not print.'] + $body, $auth($key));
$noEmail = $mandatory([], $shopKey);
check($noEmail['status'] === 422 && str_contains((string) ($noEmail['body']['message'] ?? ''), 'email address is required'), 'a shop that never gave an email cannot open a ticket without one: ' . ($noEmail['body']['message'] ?? ''));
check($mandatory(['email' => ''], $shopKey)['status'] === 422 && $mandatory(['email' => 'nonsense'], $shopKey)['status'] === 422 && $mandatory(['email' => 'a@b'], $shopKey)['status'] === 422,
    'a blank or invalid email is refused');
$before = count(mails($capture));
$appTicket = $mandatory(['email' => "shop-$unique@webchat.test"], $shopKey);
check($appTicket['status'] === 201, 'with an email the ticket opens');
$appTicketId = (int) $appTicket['body']['ticket_id'];
$withFallback = $mandatory([], $shopKey);
check($withFallback['status'] === 201, 'an older app that leaves it blank is served by the address the shop gave before');
$fallbackTicketId = (int) $withFallback['body']['ticket_id'];
check($pdo->query("SELECT customer_email FROM support_tickets WHERE id = $fallbackTicketId")->fetchColumn() === "shop-$unique@webchat.test", 'and that address is stored on the new ticket');
$sent = array_slice(mails($capture), $before);
check(count(array_filter($sent, static fn (array $m) => $m['to'] === "shop-$unique@webchat.test")) === 2, 'each new ticket acknowledges to that address');
$before = count(mails($capture));
call($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $appTicketId, 'message' => 'We are looking into the printer.'], $admin);
$sent = array_slice(mails($capture), $before);
check(count($sent) === 1 && $sent[0]['to'] === "shop-$unique@webchat.test" && str_contains($sent[0]['text'], 'We are looking into the printer.')
    && str_contains($sent[0]['text'], 'Open NexaPOS > Support'), 'a reply to an app ticket is copied to its email too');
// a visitor's token opens the photos of THEIR chat only - never another ticket's, however its id is guessed
call($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $appTicketId, 'message' => 'Photo for the shop.', 'attachments' => [['data' => $png]]], $admin);
$shopPhotoId = (int) $pdo->query("SELECT id FROM support_attachments WHERE ticket_id = $appTicketId LIMIT 1")->fetchColumn();
check($shopPhotoId > 0 && $shopPhotoId !== $attachmentId, 'setup: a shop ticket has a photo of its own');
check(call($baseUrl, 'web_chat_attachment', 'GET', null, $tokenHeader($token), ['ticket_id' => $ticketId, 'id' => $shopPhotoId])['status'] === 404,
    'a visitor cannot fetch a photo that belongs to another ticket');
check(call($baseUrl, 'web_chat_attachment', 'GET', null, $tokenHeader($token), ['ticket_id' => $appTicketId, 'id' => $shopPhotoId])['status'] === 404,
    'nor name a shop ticket as their own');
// a ticket from before the rule (no email on it) is answered at the shop's address
$pdo->exec("INSERT INTO support_tickets (shop_id, opened_by_client_id, subject, customer_email, status) SELECT shop_id, opened_by_client_id, 'Old ticket', NULL, 'open' FROM support_tickets WHERE id = $appTicketId");
$oldId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO support_messages (ticket_id, client_id, sender, body) SELECT $oldId, opened_by_client_id, 'customer', 'Old question' FROM support_tickets WHERE id = $oldId");
$before = count(mails($capture));
call($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $oldId, 'message' => 'Sorry for the wait.'], $admin);
$sent = array_slice(mails($capture), $before);
check(count($sent) === 1 && $sent[0]['to'] === "shop-$unique@webchat.test", 'a ticket with no email is answered at the address its shop gave on another ticket');
$otherShop = (string) call($baseUrl, 'register_device', 'POST', ['device_id' => "webchat-solo-$unique", 'device_label' => 'Solo', 'registration_secret' => "secret-solo-$unique"])['body']['api_key'];
$pdo->exec("INSERT INTO support_tickets (shop_id, opened_by_client_id, subject, customer_email, status) SELECT shop_id, id, 'Never had an email', NULL, 'open' FROM clients WHERE device_id = 'webchat-solo-$unique'");
$noAddressId = (int) $pdo->lastInsertId();
$before = count(mails($capture));
$noCopy = call($baseUrl, 'admin_support_reply', 'POST', ['ticket_id' => $noAddressId, 'message' => 'Hello?'], $admin);
check($noCopy['status'] === 200 && ($noCopy['body']['emailed'] ?? null) === false && count(mails($capture)) === $before,
    'with no address anywhere the reply is still saved and simply not emailed');

// --- limits: nobody can use the public chat to flood the inbox or mail strangers
// (counters from the checks above are moved out of the way first, so each limit is proven alone)
$pdo->exec("UPDATE support_messages SET source_ip_hash = 'earlier-run-part' WHERE source_ip_hash IS NOT NULL");
$repeat = static fn (string $text) => $start(['email' => $visitorEmail, 'message' => $text]);
check($repeat('Second chat from the same email')['status'] === 201 && $repeat('Third chat from the same email')['status'] === 201,
    'a second and third chat from one email address are allowed');
check($repeat('Fourth chat from the same email')['status'] === 429, 'a fourth chat for one email address in a day is refused (so nobody can be mailed again and again)');
check($start(['email' => "fresh1-$unique@webchat.test", 'message' => 'A different email'])['status'] === 201
    && $start(['email' => "fresh2-$unique@webchat.test", 'message' => 'Another email'])['status'] === 201, 'other email addresses are unaffected');
check($start(['email' => "fresh3-$unique@webchat.test", 'message' => 'One more email'])['status'] === 429, 'but one network address cannot start more than four chats an hour');

// 30 messages per 10 minutes from one address; 200 per chat; 400 an hour overall
$flood = 0;
do {
    $r = $sendMessage($token, ['message' => 'flood ' . $flood]);
    $flood += $r['status'] === 200 ? 1 : 0;
} while ($r['status'] === 200 && $flood < 60);
check($r['status'] === 429 && $flood === 26, "messages from one address are limited to 30 per 10 minutes (26 left after the 4 chats started above): $flood");
$pdo->exec("UPDATE support_messages SET created_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE source_ip_hash IS NOT NULL");
check($sendMessage($token, ['message' => 'back to normal'])['status'] === 200, 'the limit lifts once the window has passed');
$pdo->exec("INSERT INTO support_messages (ticket_id, client_id, sender, body) SELECT $ticketId, NULL, 'customer', 'filler' FROM support_messages a CROSS JOIN support_messages b LIMIT 200");
check($sendMessage($token, ['message' => 'one too many'])['status'] === 429, 'a chat that holds 200 visitor messages is full');
$pdo->exec("DELETE FROM support_messages WHERE body = 'filler'");
$pdo->exec("INSERT INTO support_messages (ticket_id, client_id, sender, body, source_ip_hash) SELECT $ticketId, NULL, 'support', 'filler', 'elsewhere' FROM support_messages a CROSS JOIN support_messages b LIMIT 400");
$busy = $sendMessage($token, ['message' => 'while very busy']);
$busyStart = $start(['email' => "busy-$unique@webchat.test", 'message' => 'while very busy']);
$pdo->exec("DELETE FROM support_messages WHERE body = 'filler'");
check($busy['status'] === 429 && $busyStart['status'] === 429, 'an overall cap protects the inbox when chat is flooded from many addresses');

// photos: 12 an hour from one address, 150 an hour overall
$photoSend = static fn (int $n) => $sendMessage($token, ['message' => '', 'attachments' => array_fill(0, $n, ['data' => $png])])['status'];
check($photoSend(4) === 200 && $photoSend(4) === 200 && $photoSend(4) === 200, 'twelve photos an hour from one address are fine');
check($photoSend(1) === 429, 'the thirteenth is refused');
$pdo->exec("UPDATE support_messages SET created_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE source_ip_hash IS NOT NULL");
$pdo->exec("INSERT INTO support_messages (ticket_id, client_id, sender, body, source_ip_hash) VALUES ($ticketId, NULL, 'support', 'filler', 'elsewhere')");
$fillerMessage = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO support_attachments (ticket_id, message_id, mime_type, byte_size, image_data) SELECT $ticketId, $fillerMessage, 'image/png', 1, 'x' FROM support_messages a CROSS JOIN support_messages b LIMIT 150");
$busyPhoto = $photoSend(1);
$pdo->exec("DELETE FROM support_messages WHERE body = 'filler'");
check($busyPhoto === 429, 'an overall cap on photos protects the database when many addresses upload at once');
check($photoSend(1) === 200, 'and uploads work again once that passes');

// --- the browser on nexapos.cc is allowed to send the token
$preflight = call($baseUrl, 'web_chat_thread', 'OPTIONS', null, ['Origin: https://nexapos.cc', 'Access-Control-Request-Method: GET', 'Access-Control-Request-Headers: x-chat-token']);
check($preflight['status'] === 204 && stripos($preflight['head'], 'Access-Control-Allow-Origin: https://nexapos.cc') !== false && stripos($preflight['head'], 'X-Chat-Token') !== false,
    'the website\'s own origin may call the chat (preflight allows X-Chat-Token)');
$stranger = call($baseUrl, 'web_chat_thread', 'OPTIONS', null, ['Origin: https://evil.example', 'Access-Control-Request-Method: GET']);
check($stranger['status'] === 403, 'another website may not');

$pdo->exec("DELETE FROM support_tickets WHERE customer_email LIKE '%@webchat.test' OR id IN ($oldId, $noAddressId)");
echo $failures === 0 ? "\nAll website chat checks passed.\n" : "\n$failures website chat check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
