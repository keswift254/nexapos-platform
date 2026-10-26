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
 *    says which shop and which device (its ID) wrote - or, for a chat started on the website,
 *    which visitor.
 *  - customer: to the address the shop (or website visitor) gave, "NexaPOS support ticket
 *    #12: subject". Every reply support writes is copied there.
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
     * channel is 'website' for a chat started on the public site (then visitorName says who, and
     * shopName/shopId/deviceId mean nothing); anything else is an app ticket.
     *
     * @param array{ticketId:int,subject:string,shopName:string,shopId:int,deviceId:string,deviceLabel:string,customerEmail:?string,message:string,photoCount:int,channel?:string,visitorName?:string} $ticket
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
                self::isWebsite($ticket)
                    ? "Thanks for contacting NexaPOS support - this is ticket #{$ticket['ticketId']}."
                        . "\n\nYour message:\n\n" . $ticket['message']
                        . "\n\nWe answer in the chat on nexapos.cc and email you a copy of every reply here. "
                        . 'You can also just reply to this email.'
                    : "We received your support ticket #{$ticket['ticketId']}: " . self::line($ticket['subject'])
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

    /**
     * Support answered: a copy goes to the client's email address (if the ticket has one), in the
     * customer's thread. True when the mail server accepted it - the dashboard tells the vendor.
     */
    public function supportReplied(array $ticket, int $messageId): bool
    {
        $email = (string) ($ticket['customerEmail'] ?? '');
        if (!$this->enabled() || $email === '') {
            return false;
        }
        $root = $this->customerRoot($ticket['ticketId']);
        $text = trim($ticket['message']);
        $count = (int) $ticket['photoCount'];
        $photos = $count > 0
            ? "\n\n($count photo" . ($count === 1 ? '' : 's') . ' attached - open '
                . (self::isWebsite($ticket) ? 'the chat on nexapos.cc' : 'NexaPOS > Support') . ' to see '
                . ($count === 1 ? 'it' : 'them') . '.)'
            : '';
        return $this->mailer->send(
            $email,
            self::customerSubject($ticket),
            "NexaPOS Support replied to ticket #{$ticket['ticketId']}: " . self::line($ticket['subject'])
                . "\n\n" . ($text !== '' ? $text : '(photo only)') . $photos
                . (self::isWebsite($ticket)
                    ? "\n\nContinue the chat at https://nexapos.cc or just reply to this email."
                    : "\n\nOpen NexaPOS > Support to view or reply. Replying to a closed ticket reopens it."),
            [
                'Message-ID' => $this->id("ticket-{$ticket['ticketId']}-customer-m$messageId"),
                'In-Reply-To' => $root,
                'References' => $root,
            ],
        );
    }

    public static function internalSubject(array $ticket): string
    {
        if (self::isWebsite($ticket)) {
            $name = self::line($ticket['visitorName'] ?? '');
            $who = 'Website visitor' . ($name !== '' ? " ($name)" : '');
            return mb_substr("[#{$ticket['ticketId']}] $who - " . self::line($ticket['subject']), 0, 180);
        }
        $shop = self::line($ticket['shopName']) ?: ('Shop #' . $ticket['shopId']);
        return mb_substr("[#{$ticket['ticketId']}] $shop - " . self::line($ticket['subject']), 0, 180);
    }

    /** True for a chat started on the public website rather than from a shop's app. */
    public static function isWebsite(array $ticket): bool
    {
        return ($ticket['channel'] ?? 'app') === 'website';
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
        $contact = 'Contact: ' . (($ticket['customerEmail'] ?? '') !== '' ? $ticket['customerEmail'] : 'no email given');
        if (self::isWebsite($ticket)) {
            $name = self::line($ticket['visitorName'] ?? '');
            $lines = [
                $headline,
                '',
                'From:    Website visitor' . ($name !== '' ? " - $name" : ''),
                "Ticket:  #{$ticket['ticketId']} - " . self::line($ticket['subject']),
                $contact,
            ];
        } else {
            $shop = self::line($ticket['shopName']) ?: 'unnamed shop';
            $label = self::line($ticket['deviceLabel']);
            $lines = [
                $headline,
                '',
                "Shop:    $shop (shop #{$ticket['shopId']})",
                'Device:  ' . ($label !== '' ? "$label - " : '') . 'ID ' . (self::line($ticket['deviceId']) ?: 'unknown'),
                "Ticket:  #{$ticket['ticketId']} - " . self::line($ticket['subject']),
                $contact,
            ];
        }
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
