<?php

declare(strict_types=1);

namespace ReBuji\Mail\Imap;

use DateTimeImmutable;
use Override;

/**
 * The reply mailbox over IMAP. One instance keeps its connection and logs out when it goes away.
 *
 * @author shimomo
 */
final class ImapMailbox implements Mailbox
{
    private ?ImapClient $client = null;

    public function __construct(private readonly ?ImapSettings $settings)
    {
        //
    }

    public function __destruct()
    {
        $this->client?->close();
    }

    #[Override]
    public function unseen(DateTimeImmutable $since, int $limit): array
    {
        $client = $this->client();
        $mails = [];

        foreach (array_slice($client->unseenSince($since), 0, $limit) as $uid) {
            $mails[] = new RawMail($uid, $client->fetch($uid));
        }

        return $mails;
    }

    #[Override]
    public function markSeen(RawMail $mail): void
    {
        $this->client()->markSeen($mail->uid);
    }

    private function client(): ImapClient
    {
        if ($this->settings === null) {
            throw new MailboxUnavailable('返信を読む設定（IMAP）がありません。「設定」で登録してください。');
        }

        if ($this->client === null) {
            $client = new ImapClient($this->settings);
            $client->open();
            $this->client = $client;
        }

        return $this->client;
    }
}
