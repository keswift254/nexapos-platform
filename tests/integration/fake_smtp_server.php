<?php

declare(strict_types=1);

// TEST ONLY - a tiny SMTP server that records what it is sent.
//   php fake_smtp_server.php <plain|starttls|ssl> <port> <outfile> <both|plain|login|none> <user> <pass> [cert.pem]
// Every accepted message is appended to <outfile> as one JSON line:
//   {"type":"mail","from":"...","to":["..."],"data":"<everything between DATA and the final dot>"}
// A failed sign-in appends {"type":"auth_failed","method":"..."}.
// It serves one connection at a time and runs until it is killed.

[, $mode, $port, $outfile, $auth, $user, $pass] = $argv + array_fill(0, 7, '');
$cert = $argv[7] ?? '';

$context = stream_context_create(['ssl' => [
    'local_cert' => $cert,
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$scheme = $mode === 'ssl' ? 'ssl' : 'tcp';
$server = stream_socket_server("$scheme://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
echo "READY\n";
fflush(STDOUT);

function record(string $outfile, array $event): void
{
    file_put_contents($outfile, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

while (true) {
    $conn = @stream_socket_accept($server, 3600);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 10);
    $say = static function (string $line) use ($conn): void {
        fwrite($conn, $line . "\r\n");
    };
    $say('220 fake.smtp ESMTP ready');
    $from = '';
    $to = [];
    $signedIn = $auth === 'none';
    $encrypted = $mode === 'ssl';
    while (($line = fgets($conn, 4096)) !== false) {
        $line = rtrim($line, "\r\n");
        $verb = strtoupper(strtok($line, ' ') ?: '');
        if ($verb === 'EHLO' || $verb === 'HELO') {
            $features = ['250-fake.smtp'];
            if ($mode === 'starttls' && !$encrypted) {
                $features[] = '250-STARTTLS';
            }
            $methods = match ($auth) {
                'both' => 'PLAIN LOGIN',
                'plain' => 'PLAIN',
                'login' => 'LOGIN',
                default => '',
            };
            // A real server only offers AUTH after the connection is encrypted.
            if ($methods !== '' && ($mode === 'plain' || $encrypted)) {
                $features[] = '250-AUTH ' . $methods;
            }
            $features[] = '250 8BITMIME';
            fwrite($conn, implode("\r\n", $features) . "\r\n");
        } elseif ($verb === 'STARTTLS' && $mode === 'starttls') {
            $say('220 go ahead');
            if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                break;
            }
            $encrypted = true;
        } elseif ($verb === 'AUTH') {
            $parts = explode(' ', $line);
            $method = strtoupper($parts[1] ?? '');
            $ok = false;
            if ($method === 'PLAIN') {
                $decoded = explode("\0", (string) base64_decode($parts[2] ?? '', true));
                $ok = ($decoded[1] ?? null) === $user && ($decoded[2] ?? null) === $pass;
            } elseif ($method === 'LOGIN') {
                $say('334 ' . base64_encode('Username:'));
                $gotUser = (string) base64_decode(rtrim((string) fgets($conn, 4096)), true);
                $say('334 ' . base64_encode('Password:'));
                $gotPass = (string) base64_decode(rtrim((string) fgets($conn, 4096)), true);
                $ok = $gotUser === $user && $gotPass === $pass;
            }
            if ($ok) {
                $signedIn = true;
                $say('235 2.7.0 Authentication successful');
            } else {
                record($outfile, ['type' => 'auth_failed', 'method' => $method]);
                $say('535 5.7.8 Incorrect authentication data');
            }
        } elseif ($verb === 'MAIL') {
            if (!$signedIn) {
                $say('530 5.7.0 Authentication required');
                continue;
            }
            $from = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
            $to = [];
            $say('250 OK');
        } elseif ($verb === 'RCPT') {
            $to[] = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
            $say('250 OK');
        } elseif ($verb === 'DATA') {
            $say('354 end with <CRLF>.<CRLF>');
            $data = '';
            while (($row = fgets($conn, 4096)) !== false) {
                if ($row === ".\r\n") {
                    break;
                }
                $data .= $row;
            }
            record($outfile, ['type' => 'mail', 'from' => $from, 'to' => $to, 'data' => $data]);
            $say('250 2.0.0 queued');
        } elseif ($verb === 'QUIT') {
            $say('221 bye');
            break;
        } else {
            $say('502 5.5.2 command not recognized');
        }
    }
    fclose($conn);
}
