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
     * Unseen mail received since the given day, oldest first, at most $limit of it. Each mail may be fetched only when
     * it is reached, so iterating may throw too.
     *
     * @return iterable<RawMail>
     *
     * @throws MailboxUnavailable
     */
    public function unseen(DateTimeImmutable $since, int $limit): iterable;

    /**
     * Unlike unseen(), mail that is already read too: mail received since the given day that answers the given one (its
     * Message-ID in In-Reply-To or References) or has the token in its subject, oldest first, at most $limit of it.
     * New mail is checked for before searching, so a long-open connection sees what arrived meanwhile. An empty
     * $messageId or $token is not a condition; with neither, nothing is returned.
     *
     * @return iterable<RawMail>
     *
     * @throws MailboxUnavailable
     */
    public function answersTo(DateTimeImmutable $since, string $messageId, string $token, int $limit): iterable;

    /**
     * Marks the mail as read so it is not counted twice. Mail is never deleted (design 5.2).
     *
     * @throws MailboxUnavailable
     */
    public function markSeen(RawMail $mail): void;
}
