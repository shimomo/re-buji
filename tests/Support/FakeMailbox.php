<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use Override;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Mail\Imap\RawMail;

/**
 * A reply mailbox in memory. Records what it was asked for and what was marked seen.
 *
 * @author shimomo
 */
final class FakeMailbox implements Mailbox
{
    /**
     * @var list<int>
     */
    public array $seen = [];

    public bool $unavailable = false;

    /**
     * @var list<array{since: DateTimeImmutable, limit: int}>
     */
    public array $asked = [];

    /**
     * @var array<int, string>
     */
    private array $mails = [];

    public function add(string $raw): int
    {
        $uid = count($this->mails) + 1;
        $this->mails[$uid] = $raw;

        return $uid;
    }

    #[Override]
    public function unseen(DateTimeImmutable $since, int $limit): array
    {
        $this->asked[] = ['since' => $since, 'limit' => $limit];

        if ($this->unavailable) {
            throw new MailboxUnavailable('Connection to imap.example.co.jp:993 timed out');
        }

        $unseen = [];

        foreach ($this->mails as $uid => $raw) {
            if (!in_array($uid, $this->seen, true) && count($unseen) < $limit) {
                $unseen[] = new RawMail($uid, $raw);
            }
        }

        return $unseen;
    }

    #[Override]
    public function markSeen(RawMail $mail): void
    {
        $this->seen[] = $mail->uid;
    }
}
