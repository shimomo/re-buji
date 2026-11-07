<?php

declare(strict_types=1);

namespace ReBuji\Mail\Imap;

use DateTimeImmutable;
use Generator;
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

    /**
     * Logs in and searches at once; fetches each mail only when it is reached, so one run holds one mail at a time.
     */
    #[Override]
    public function unseen(DateTimeImmutable $since, int $limit): iterable
    {
        $client = $this->client();

        return $this->fetching($client, array_slice($client->unseenSince($since), 0, $limit));
    }

    #[Override]
    public function markSeen(RawMail $mail): void
    {
        $this->client()->markSeen($mail->uid);
    }

    /**
     * Not static: the generator keeps the mailbox, and so the connection, alive while it is iterated.
     *
     * @param list<int> $uids
     *
     * @return Generator<int, RawMail>
     */
    private function fetching(ImapClient $client, array $uids): Generator
    {
        foreach ($uids as $uid) {
            yield new RawMail($uid, $client->fetch($uid));
        }
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
