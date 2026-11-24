<?php

declare(strict_types=1);

namespace ReBuji\Install;

use DateTimeImmutable;
use InvalidArgumentException;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Replies\IncomingMail;

/**
 * Looks for the reply to the setup test mail (design 6.3, 3): shared by the installer and bin/console check.
 *
 * @author shimomo
 */
final class SetupReplyFinder
{
    public function __construct(private readonly Mailbox $mailbox)
    {
        //
    }

    /**
     * True when the reply to the test mail is in the mailbox, read or not. The reply is marked as read.
     * The server's narrowing is only a guide: the checks below decide whether a mail is the reply.
     *
     * @throws MailboxUnavailable
     */
    public function find(DateTimeImmutable $since, string $messageId, string $number): bool
    {
        foreach ($this->mailbox->answersTo($since, $messageId, $number === '' ? '' : '#' . $number, 50) as $raw) {
            try {
                $mail = IncomingMail::parse($raw->raw);
            } catch (InvalidArgumentException) {
                continue;
            }

            // Bounces, delays, auto-replies and read receipts are left as they are for the reply job.
            if ($mail->kind() !== IncomingMail::REPLY) {
                continue;
            }

            if (($messageId !== '' && in_array($messageId, $mail->references, true)) || ($number !== '' && str_contains($mail->subject, '#' . $number))) {
                $this->mailbox->markSeen($raw);

                return true;
            }
        }

        return false;
    }
}
