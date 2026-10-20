<?php

declare(strict_types=1);

namespace Platform\Services;

/**
 * The emails a support ticket sends, in one place.
 *
 * Two separate conversations per ticket, each kept in ONE thread in the mail client:
 *  - internal: to the support mailbox, for whoever answers tickets. Subject
 *    "[#12] Shop name - ticket subject" never changes, the first mail's Message-ID is the
 *    thread root, and every later mail points at it (In-Reply-To / References). Each one
 *    says which shop and which device (its ID) wrote.
 *  - customer: to the address the shop gave, "NexaPOS support ticket #12: subject".
 *
 * Sending never throws and never blocks the API call that caused it.
 */
final class SupportNotifier
{
    public function __construct(
        private readonly SupportMailer $mailer,
        private readonly string $supportAddress,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        return new self(SupportMailer::fromConfig($config), trim((string) ($config['support_email_from'] ?? '')));
    }

    /**
     * What the notifications need to know. Every value may be hostile (a shop typed it).
     *
     * @param array{ticketId:int,subject:string,shopName:string,shopId:int,deviceId:string,deviceLabel:string,customerEmail:?string,message:string,photoCount:int} $ticket
     */
    public function ticketOpened(array $ticket): void
    {
        if (!$this->enabled()) {
            return;
        }
        $this->mailer->send(
            $this->supportAddress,
            self::internalSubject($ticket),
            $this->internalBody('A shop opened a new support ticket.', $ticket),
            ['Message-ID' => $this->internalRoot($ticket['ticketId'])],
        );
        $email = (string) ($ticket['customerEmail'] ?? '');
        if ($email !== '') {
            $this->mailer->send(
                $email,
                self::customerSubject($ticket),
                "We received your support ticket #{$ticket['ticketId']}: " . self::line($ticket['subject'])
                    . "\n\n" . $ticket['message']
                    . "\n\nOpen NexaPOS > Support to view the conversation.",
                ['Message-ID' => $this->customerRoot($ticket['ticketId'])],
            );
        }
    }

    /** The shop wrote again: lands in the same internal thread as the ticket it belongs to. */
    public function customerReplied(array $ticket, int $messageId): void
    {
        if (!$this->enabled()) {
            return;
        }
        $root = $this->internalRoot($ticket['ticketId']);
        $this->mailer->send(
            $this->supportAddress,
            self::internalSubject($ticket),
            $this->internalBody('The shop replied.', $ticket),
            [
                'Message-ID' => $this->id("ticket-{$ticket['ticketId']}-m$messageId"),
                'In-Reply-To' => $root,
                'References' => $root,
            ],
        );
    }

    /** Support answered: to the shop's email address (if it gave one), in the customer's thread. */
    public function supportReplied(array $ticket, int $messageId): void
    {
        $email = (string) ($ticket['customerEmail'] ?? '');
        if (!$this->enabled() || $email === '') {
            return;
        }
        $root = $this->customerRoot($ticket['ticketId']);
        $this->mailer->send(
            $email,
            self::customerSubject($ticket),
            "NexaPOS Support replied to ticket #{$ticket['ticketId']}: " . self::line($ticket['subject'])
                . "\n\n" . $ticket['message']
                . "\n\nOpen NexaPOS > Support to view or reply. Replying to a closed ticket reopens it.",
            [
                'Message-ID' => $this->id("ticket-{$ticket['ticketId']}-customer-m$messageId"),
                'In-Reply-To' => $root,
                'References' => $root,
            ],
        );
    }

    public static function internalSubject(array $ticket): string
    {
        $shop = self::line($ticket['shopName']) ?: ('Shop #' . $ticket['shopId']);
        return mb_substr("[#{$ticket['ticketId']}] $shop - " . self::line($ticket['subject']), 0, 180);
    }

    public static function customerSubject(array $ticket): string
    {
        return mb_substr("NexaPOS support ticket #{$ticket['ticketId']}: " . self::line($ticket['subject']), 0, 180);
    }

    /** One line, no control characters (they would be a header injection in a subject). */
    public static function line(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    }

    private function internalBody(string $headline, array $ticket): string
    {
        $shop = self::line($ticket['shopName']) ?: 'unnamed shop';
        $label = self::line($ticket['deviceLabel']);
        $lines = [
            $headline,
            '',
            "Shop:    $shop (shop #{$ticket['shopId']})",
            'Device:  ' . ($label !== '' ? "$label - " : '') . 'ID ' . (self::line($ticket['deviceId']) ?: 'unknown'),
            "Ticket:  #{$ticket['ticketId']} - " . self::line($ticket['subject']),
            'Contact: ' . (($ticket['customerEmail'] ?? '') !== '' ? $ticket['customerEmail'] : 'no email given'),
        ];
        if ($ticket['photoCount'] > 0) {
            $lines[] = 'Photos:  ' . $ticket['photoCount'] . ' attached (open the dashboard to see them)';
        }
        $text = trim($ticket['message']);
        array_push($lines, '', $text !== '' ? $text : '(no text - photo only)', '', 'Reply from the NexaPOS admin dashboard.');
        return implode("\n", $lines);
    }

    private function enabled(): bool
    {
        return filter_var($this->supportAddress, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function domain(): string
    {
        return substr((string) strrchr($this->supportAddress, '@'), 1) ?: 'localhost';
    }

    private function id(string $local): string
    {
        return '<nexapos-' . $local . '@' . $this->domain() . '>';
    }

    private function internalRoot(int $ticketId): string
    {
        return $this->id("ticket-$ticketId");
    }

    private function customerRoot(int $ticketId): string
    {
        return $this->id("ticket-$ticketId-customer");
    }
}
