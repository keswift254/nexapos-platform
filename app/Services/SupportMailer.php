<?php

declare(strict_types=1);

namespace Platform\Services;

final class SupportMailer
{
    public function __construct(
        private readonly string $from,
        private readonly ?SmtpClient $smtp = null,
    ) {
    }

    /**
     * Builds the mailer from the platform config. When `support_smtp.host` is set,
     * messages go out through authenticated SMTP (the only way that works on the
     * NameCrane account, whose PHP mail() is refused by the server); otherwise the
     * old PHP mail() path is kept for local development and tests.
     */
    public static function fromConfig(array $config): self
    {
        $from = trim((string) ($config['support_email_from'] ?? ''));
        $smtp = (array) ($config['support_smtp'] ?? []);
        $host = trim((string) ($smtp['host'] ?? ''));
        if ($host === '') {
            return new self($from);
        }
        $secure = strtolower((string) ($smtp['secure'] ?? 'tls'));
        if (!in_array($secure, ['ssl', 'tls', 'none'], true)) {
            $secure = 'tls';
        }
        $port = (int) ($smtp['port'] ?? 0) ?: match ($secure) {
            'ssl' => 465,
            'tls' => 587,
            default => 25,
        };
        // Talking to this very server by name "localhost": its certificate is issued
        // for the server's public name, so the name check cannot pass.
        $local = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
        $verify = array_key_exists('verify_peer', $smtp) ? (bool) $smtp['verify_peer'] : !$local;
        return new self($from, new SmtpClient(
            $host,
            $port,
            $secure,
            (string) ($smtp['user'] ?? ''),
            (string) ($smtp['pass'] ?? ''),
            (int) ($smtp['timeout'] ?? 12) ?: 12,
            $verify,
        ));
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (!filter_var($this->from, FILTER_VALIDATE_EMAIL)
            || !filter_var($to, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $this->from . $to . $subject)
        ) {
            return false;
        }
        if ($this->smtp !== null) {
            return $this->smtp->send($this->from, 'NexaPOS Support', $to, $subject, $body);
        }
        $headers = [
            'From: NexaPOS Support <' . $this->from . '>',
            'Reply-To: ' . $this->from,
            'Content-Type: text/plain; charset=UTF-8',
            'X-Auto-Response-Suppress: All',
        ];
        try {
            $sent = mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $this->from);
            if (!$sent) {
                error_log('[nexapos_platform] support email was not accepted by the local mail transport.');
            }
            return $sent;
        } catch (\Throwable $e) {
            error_log('[nexapos_platform] support email failed: ' . $e->getMessage());
            return false;
        }
    }
}
