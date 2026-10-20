<?php

declare(strict_types=1);

namespace Platform\Services;

/**
 * A small, dependency-free SMTP sender: one message over an authenticated
 * connection (implicit SSL on 465, or STARTTLS on 587). It exists because the
 * hosting account is not allowed to use the server's local sendmail (PHP mail()
 * fails with "account not permitted to send mail"), while authenticated SMTP
 * through a real mailbox is allowed.
 *
 * Never throws: send() returns false and leaves the reason in $lastError (the
 * password is never part of it).
 */
final class SmtpClient
{
    public ?string $lastError = null;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $secure, // 'ssl' (implicit TLS), 'tls' (STARTTLS) or 'none'
        private readonly string $user,
        private readonly string $pass,
        private readonly int $timeoutSeconds = 12,
        private readonly bool $verifyPeer = true,
    ) {
    }

    public function send(string $fromAddress, string $fromName, string $to, string $subject, string $body): bool
    {
        $this->lastError = null;
        try {
            $this->connect();
            $this->expect([220]);
            $features = $this->hello();
            if ($this->secure === 'tls') {
                $this->command('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('TLS negotiation with the mail server failed.');
                }
                $features = $this->hello();
            }
            if ($this->user !== '') {
                $this->authenticate($features);
            }
            $this->command('MAIL FROM:<' . $fromAddress . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            // The body is base64, so it never contains a line that starts with "." and
            // needs no dot-stuffing.
            $this->write($this->message($fromAddress, $fromName, $to, $subject, $body) . "\r\n.\r\n");
            $this->expect([250]);
            try {
                $this->command('QUIT', [221]);
            } catch (\Throwable) {
                // The message is already accepted; a rude goodbye changes nothing.
            }
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[nexapos_platform] SMTP send failed: ' . $e->getMessage());
            return false;
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
                'SNI_enabled' => true,
            ],
        ]);
        $scheme = $this->secure === 'ssl' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            $scheme . '://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new \RuntimeException("Could not connect to the mail server ($errstr).");
        }
        stream_set_timeout($socket, $this->timeoutSeconds);
        $this->socket = $socket;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /** @return string[] the server's feature lines (upper-case) */
    private function hello(): array
    {
        $name = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost')) ?: 'localhost';
        try {
            $reply = $this->command('EHLO ' . $name, [250]);
        } catch (\RuntimeException) {
            $reply = $this->command('HELO ' . $name, [250]);
        }
        return array_map('strtoupper', array_slice(preg_split('/\r?\n/', trim($reply)) ?: [], 1));
    }

    private function authenticate(array $features): void
    {
        $offered = implode(' ', $features);
        if (str_contains($offered, 'AUTH') && str_contains($offered, 'PLAIN')) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->user . "\0" . $this->pass), [235], 'signing in');
            return;
        }
        if (str_contains($offered, 'AUTH') && str_contains($offered, 'LOGIN')) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->user), [334], 'signing in');
            $this->command(base64_encode($this->pass), [235], 'signing in');
            return;
        }
        throw new \RuntimeException('The mail server offers no sign-in method this app supports.');
    }

    private function message(string $fromAddress, string $fromName, string $to, string $subject, string $body): string
    {
        $domain = substr(strrchr($fromAddress, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromAddress . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $fromAddress,
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Auto-Response-Suppress: All',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private static function encodeHeader(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        return preg_match('/^[\x20-\x7E]*$/', $text) ? $text : '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /**
     * @param int[] $accept
     * @param string|null $label what to call this step in an error message; always set it
     *                           for anything that carries the user name or password
     */
    private function command(string $line, array $accept, ?string $label = null): string
    {
        $this->write($line . "\r\n");
        return $this->expect($accept, $label ?? $line);
    }

    private function write(string $data): void
    {
        $length = strlen($data);
        for ($written = 0; $written < $length;) {
            $chunk = @fwrite($this->socket, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                throw new \RuntimeException('The mail server closed the connection.');
            }
            $written += $chunk;
        }
    }

    /** @param int[] $accept */
    private function expect(array $accept, string $after = 'connect'): string
    {
        $reply = '';
        do {
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new \RuntimeException(!empty($meta['timed_out'])
                    ? 'The mail server did not answer in time.'
                    : 'The mail server closed the connection.');
            }
            $reply .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $accept, true)) {
            // Never echo what was typed after AUTH; the server's own words are safe.
            throw new \RuntimeException('Mail server replied "' . trim(substr($reply, 0, 160)) . '" after ' . $after . '.');
        }
        return $reply;
    }
}
