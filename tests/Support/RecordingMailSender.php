<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use Override;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\MailUnavailable;
use ReBuji\Mail\OutgoingMail;
use ReBuji\Mail\RecipientRefused;

/**
 * Keeps what would have been sent. Recipients can be set to be refused, or the whole server to be unavailable.
 *
 * @author shimomo
 */
final class RecordingMailSender implements MailSender
{
    /**
     * @var list<OutgoingMail>
     */
    public array $sent = [];

    public bool $unavailable = false;

    /**
     * @param array<string, 'refuse'|'unavailable'> $failures by recipient address
     */
    public function __construct(public array $failures = [])
    {
        //
    }

    #[Override]
    public function send(OutgoingMail $mail): void
    {
        $failure = $this->failures[$mail->to] ?? null;

        if ($this->unavailable || $failure === 'unavailable') {
            throw new MailUnavailable('Connection to "smtp.example.co.jp:587" timed out.');
        }

        if ($failure === 'refuse') {
            throw new RecipientRefused('550 5.1.1 User unknown: ' . $mail->to);
        }

        $this->sent[] = $mail;
    }

    public function clear(): void
    {
        $this->sent = [];
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_map(static fn(OutgoingMail $mail): string => $mail->to, $this->sent);
    }
}
