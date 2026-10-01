<?php

declare(strict_types=1);

namespace Platform\Services;

final class SupportMailer
{
    public function __construct(private readonly string $from)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (!filter_var($this->from, FILTER_VALIDATE_EMAIL)
            || !filter_var($to, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $this->from . $to . $subject)
        ) {
            return false;
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
