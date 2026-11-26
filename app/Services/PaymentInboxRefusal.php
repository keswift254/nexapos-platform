<?php

declare(strict_types=1);

namespace Platform\Services;

/** The payment inbox saying no: an HTTP status, a short machine-readable reason, words for the person, and any details. */
final class PaymentInboxRefusal extends \RuntimeException
{
    public int $status;
    public string $reason;
    /** @var array<string,mixed> */
    public array $details;

    /** @param array<string,mixed> $details */
    public function __construct(int $status, string $reason, string $message, array $details = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->reason = $reason;
        $this->details = $details;
    }
}
