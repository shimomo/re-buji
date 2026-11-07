<?php

declare(strict_types=1);

namespace ReBuji\Mail\Imap;

use DateTimeImmutable;

/**
 * The way replies come back (design 5.7): the rest of the app only knows this.
 *
 * @author shimomo
 */
interface Mailbox
{
    /**
     * Unseen mail received since the given day, oldest first, at most $limit of it.
     *
     * @return list<RawMail>
     *
     * @throws MailboxUnavailable
     */
    public function unseen(DateTimeImmutable $since, int $limit): array;

    /**
     * Marks the mail as read so it is not counted twice. Mail is never deleted (design 5.2).
     *
     * @throws MailboxUnavailable
     */
    public function markSeen(RawMail $mail): void;
}
