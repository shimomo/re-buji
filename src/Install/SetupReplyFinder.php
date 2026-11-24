<?php

declare(strict_types=1);

namespace ReBuji\Install;

use DateTimeImmutable;
use InvalidArgumentException;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Mail\Imap\RawMail;
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
     * The server's narrowing is only a guide: the checks below decide whether a mail is the reply. A server may not
     * decode an encoded-word subject, so when the search finds nothing, the unread mail is looked through as well.
     *
     * @throws MailboxUnavailable
     */
    public function find(DateTimeImmutable $since, string $messageId, string $number): bool
    {
        // the first loop ends before unseen() is called: the IMAP mailbox fetches lazily over one connection
        foreach ($this->mailbox->answersTo($since, $messageId, $number === '' ? '' : '#' . $number, 50) as $raw) {
            if ($this->isTheReply($raw, $messageId, $number)) {
                return true;
            }
        }

        foreach ($this->mailbox->unseen($since, 50) as $raw) {
            if ($this->isTheReply($raw, $messageId, $number)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks the mail as read when it is the reply.
     *
     * @throws MailboxUnavailable
     */
    private function isTheReply(RawMail $raw, string $messageId, string $number): bool
    {
        try {
            $mail = IncomingMail::parse($raw->raw);
        } catch (InvalidArgumentException) {
            return false;
        }

        // Bounces, delays, auto-replies and read receipts are left as they are for the reply job.
        if ($mail->kind() !== IncomingMail::REPLY) {
            return false;
        }

        if (($messageId !== '' && in_array($messageId, $mail->references, true)) || ($number !== '' && str_contains($mail->subject, '#' . $number))) {
            $this->mailbox->markSeen($raw);

            return true;
        }

        return false;
    }
}
