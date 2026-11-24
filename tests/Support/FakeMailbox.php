<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Mail\Imap\RawMail;
use ReBuji\Replies\IncomingMail;

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

    /**
     * @return list<RawMail>
     */
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

    /**
     * @return list<RawMail>
     */
    #[Override]
    public function answersTo(DateTimeImmutable $since, string $messageId, string $token, int $limit): array
    {
        $this->asked[] = ['since' => $since, 'limit' => $limit];

        if ($this->unavailable) {
            throw new MailboxUnavailable('Connection to imap.example.co.jp:993 timed out');
        }

        $found = [];

        // str_contains() is true for an empty string, so an empty value must not be asked
        foreach ($this->mails as $uid => $raw) {
            $matches = ($messageId !== '' && str_contains($raw, $messageId)) || ($token !== '' && (str_contains($raw, $token) || self::subjectContains($raw, $token)));

            if ($matches && count($found) < $limit) {
                $found[] = new RawMail($uid, $raw);
            }
        }

        return $found;
    }

    #[Override]
    public function markSeen(RawMail $mail): void
    {
        $this->seen[] = $mail->uid;
    }

    /**
     * A real server searches the decoded subject, so an encoded-word one must match here too.
     */
    private static function subjectContains(string $raw, string $token): bool
    {
        try {
            return str_contains(IncomingMail::parse($raw)->subject, $token);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
