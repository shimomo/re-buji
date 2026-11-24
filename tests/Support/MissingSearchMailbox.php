<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use Override;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\RawMail;

/**
 * A mailbox whose server does not decode encoded-word subjects: its search finds nothing, but the one mail is unread.
 *
 * @author shimomo
 */
final class MissingSearchMailbox implements Mailbox
{
    /**
     * @var list<int>
     */
    public array $seen = [];

    public function __construct(private readonly string $raw)
    {
        //
    }

    #[Override]
    public function unseen(DateTimeImmutable $since, int $limit): iterable
    {
        return [new RawMail(7, $this->raw)];
    }

    #[Override]
    public function answersTo(DateTimeImmutable $since, string $messageId, string $token, int $limit): iterable
    {
        return [];
    }

    #[Override]
    public function markSeen(RawMail $mail): void
    {
        $this->seen[] = $mail->uid;
    }
}
