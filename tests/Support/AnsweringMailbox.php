<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use Override;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\RawMail;

/**
 * A reply mailbox where the admin answers every mail the sender has sent, as soon as the mailbox is next asked. While
 * $answering is false, nobody answers; the mails sent meanwhile are answered once it is true again.
 *
 * @author shimomo
 */
final class AnsweringMailbox implements Mailbox
{
    public bool $answering = true;

    private int $answered = 0;

    private readonly FakeMailbox $inner;

    public function __construct(private readonly RecordingMailSender $sender)
    {
        $this->inner = new FakeMailbox();
    }

    #[Override]
    public function unseen(DateTimeImmutable $since, int $limit): iterable
    {
        if ($this->answering) {
            foreach (array_slice($this->sender->sent, $this->answered) as $mail) {
                $this->inner->add(MailFixtures::reply('admin@example.jp', '', $mail->messageId));
                $this->answered++;
            }
        }

        return $this->inner->unseen($since, $limit);
    }

    #[Override]
    public function markSeen(RawMail $mail): void
    {
        $this->inner->markSeen($mail);
    }
}
