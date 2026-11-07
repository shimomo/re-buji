<?php

declare(strict_types=1);

namespace ReBuji\Mail\Imap;

use DateTimeImmutable;

/**
 * Just enough IMAP (RFC 9051 / 3501) to read replies: log in, open the inbox, search unseen mail, fetch it, mark it
 * seen, log out. ext-imap left PHP's core in 8.4 and is often missing on rental servers (design 2.1).
 *
 * The password is only ever sent over TLS: from the start (ssl) or after STARTTLS, which is required. 'none' is only
 * for the test servers.
 *
 * @author shimomo
 */
final class ImapClient
{
    /**
     * How much of one mail is read: the words come before the attachments, and a photo need not fill the memory.
     */
    public const int MAX_BYTES = 1048576;

    /**
     * @var resource|null
     */
    private $stream = null;

    private int $tag = 0;

    public function __construct(private readonly ImapSettings $settings, private readonly int $timeoutSeconds = 15)
    {
        //
    }

    public function open(): void
    {
        $host = $this->settings->host;
        $target = ($this->settings->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $this->settings->port;
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client($target, $errorCode, $errorMessage, $this->timeoutSeconds, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new MailboxUnavailable($host . ':' . $this->settings->port . ' につなげません：' . $errorMessage);
        }

        stream_set_timeout($stream, $this->timeoutSeconds);
        $this->stream = $stream;
        $greeting = $this->readLine();

        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new MailboxUnavailable('IMAP のサーバーが応じません：' . $greeting);
        }

        if ($this->settings->encryption === 'starttls') {
            $this->startTls();
        }

        $this->command('LOGIN ' . self::quote($this->settings->username) . ' ' . self::quote($this->settings->password));
        $this->command('SELECT INBOX');
    }

    /**
     * @return list<int> UIDs of unseen mail received since that day, oldest first
     */
    public function unseenSince(DateTimeImmutable $since): array
    {
        $uids = [];

        foreach ($this->command('UID SEARCH UNSEEN SINCE ' . $since->format('j-M-Y'))['lines'] as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $match) === 1) {
                $found = preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY);

                foreach ($found === false ? [] : $found as $uid) {
                    $uids[] = (int) $uid;
                }
            }
        }

        sort($uids);

        return $uids;
    }

    /**
     * The mail as it is, up to MAX_BYTES of it.
     */
    public function fetch(int $uid): string
    {
        $literals = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . self::MAX_BYTES . '>)')['literals'];

        if ($literals === []) {
            throw new MailboxUnavailable('メール ' . $uid . ' を受け取れませんでした');
        }

        return $literals[0];
    }

    public function markSeen(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\Seen)');
    }

    public function close(): void
    {
        $stream = $this->stream;

        if ($stream === null) {
            return;
        }

        try {
            $this->command('LOGOUT');
        } catch (MailboxUnavailable) {
            // Closing anyway.
        }

        $this->stream = null;
        fclose($stream);
    }

    private function startTls(): void
    {
        $capabilities = implode(' ', $this->command('CAPABILITY')['lines']);

        if (stripos($capabilities, 'STARTTLS') === false) {
            throw new MailboxUnavailable('IMAP のサーバーが STARTTLS を使えないため、パスワードを送りません。暗号化を SSL/TLS にしてください。');
        }

        $this->command('STARTTLS');

        if ($this->stream === null || @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new MailboxUnavailable('IMAP の TLS を始められませんでした');
        }
    }

    /**
     * Sends a tagged command and reads up to its tagged answer.
     *
     * @return array{lines: list<string>, literals: list<string>} the untagged lines, and the literals they carried
     */
    private function command(string $command): array
    {
        if ($this->stream === null) {
            throw new MailboxUnavailable('IMAP のサーバーにつながっていません');
        }

        $tag = 'a' . ++$this->tag;

        if (@fwrite($this->stream, $tag . ' ' . $command . "\r\n") === false) {
            throw new MailboxUnavailable('IMAP のサーバーへ送れませんでした');
        }

        $lines = [];
        $literals = [];

        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper(substr($line, strlen($tag) + 1, 2));

                if ($status !== 'OK') {
                    throw new MailboxUnavailable('IMAP のサーバーが断りました：' . substr($line, strlen($tag) + 1));
                }

                return ['lines' => $lines, 'literals' => $literals];
            }

            $lines[] = $line;

            if (preg_match('/\{(\d+)\}$/', $line, $match) === 1) {
                $literals[] = $this->readBytes((int) $match[1]);
            }
        }
    }

    private function readLine(): string
    {
        $line = $this->stream === null ? false : fgets($this->stream);

        if ($line === false) {
            throw new MailboxUnavailable($this->timedOut() ? 'IMAP のサーバーが時間内に答えませんでした' : 'IMAP のサーバーとの接続が切れました');
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = $this->stream === null ? false : fread($this->stream, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new MailboxUnavailable($this->timedOut() ? 'IMAP のサーバーが時間内に答えませんでした' : 'IMAP のサーバーとの接続が切れました');
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function timedOut(): bool
    {
        return $this->stream !== null && (stream_get_meta_data($this->stream)['timed_out'] ?? false) === true;
    }

    private static function quote(string $value): string
    {
        return '"' . addcslashes($value, '"\\') . '"';
    }
}
