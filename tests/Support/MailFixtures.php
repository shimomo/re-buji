<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

/**
 * Raw incoming mail as the reply mailbox would hold it.
 *
 * @author shimomo
 */
final class MailFixtures
{
    public const string DATE = 'Thu, 05 Nov 2026 12:28:00 +0900';

    public static function reply(
        string $from,
        string $body,
        ?string $inReplyTo = null,
        string $subject = 'Re: [安否確認 #a1b2c3d4] 地震がありました',
        string $charset = 'UTF-8',
        ?string $messageId = null,
    ): string {
        $headers = [
            'From: ' . $from,
            'To: anpi@example.co.jp',
            'Subject: ' . self::encodedWord($subject, $charset),
            'Message-ID: <' . ($messageId ?? bin2hex(random_bytes(8)) . '@phone.example') . '>',
            'Date: ' . self::DATE,
            'MIME-Version: 1.0',
        ];

        if ($inReplyTo !== null) {
            $headers[] = 'In-Reply-To: <' . $inReplyTo . '>';
            $headers[] = 'References: <' . $inReplyTo . '>';
        }

        if ($charset === 'UTF-8') {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $encoded = chunk_split(base64_encode($body));
        } else {
            $headers[] = 'Content-Type: text/plain; charset=' . $charset;
            $headers[] = 'Content-Transfer-Encoding: 7bit';
            $encoded = (string) mb_convert_encoding(str_replace("\n", "\r\n", $body), $charset, 'UTF-8');
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $encoded;
    }

    public static function autoReply(string $from, ?string $inReplyTo = null): string
    {
        $raw = self::reply($from, "ただいま不在にしています。11月10日に戻ります。\n", $inReplyTo, '自動応答: 不在のお知らせ');

        return 'Auto-Submitted: auto-replied' . "\r\n" . $raw;
    }

    /**
     * A reply with no words, carrying a photo.
     */
    public static function withAttachment(string $from, string $inReplyTo, string $filename): string
    {
        return 'From: ' . $from . "\r\nTo: anpi@example.co.jp\r\nSubject: Re: [安否確認 #a1b2c3d4]\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@phone.example>\r\n"
            . 'Date: ' . self::DATE . "\r\nIn-Reply-To: <{$inReplyTo}>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"M\"\r\n\r\n"
            . "--M\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n\r\n"
            . "--M\r\nContent-Type: image/jpeg; name=\"{$filename}\"\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode(str_repeat("\xff\xd8\xff", 100)) . "\r\n--M--\r\n";
    }

    /**
     * A notice that delivery is delayed, not failed.
     */
    public static function delay(string $recipient, string $originalMessageId): string
    {
        return str_replace(
            ['Action: failed', 'Status: 5.1.1', 'Subject: Undelivered Mail Returned to Sender'],
            ['Action: delayed', 'Status: 4.4.1', 'Subject: Delayed Mail (still being retried)'],
            self::bounce($recipient, $originalMessageId),
        );
    }

    /**
     * A delivery status notification in the shape Postfix sends.
     */
    public static function bounce(string $recipient, ?string $originalMessageId): string
    {
        $original = "From: anpi@example.co.jp\r\nTo: {$recipient}\r\nSubject: =?UTF-8?B?" . base64_encode('[安否確認 #a1b2c3d4] 地震がありました') . "?=\r\n"
            . ($originalMessageId === null ? '' : "Message-ID: <{$originalMessageId}>\r\n") . "\r\nbody\r\n";

        return "From: MAILER-DAEMON@mail.example.co.jp (Mail Delivery System)\r\n"
            . "To: anpi@example.co.jp\r\n"
            . "Subject: Undelivered Mail Returned to Sender\r\n"
            . "Auto-Submitted: auto-replied\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(8)) . "@mail.example.co.jp>\r\n"
            . 'Date: ' . self::DATE . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"B\"\r\n"
            . "\r\n--B\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nThis is the mail system at host mail.example.co.jp.\r\n"
            . "\r\n--B\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mail.example.co.jp\r\n\r\n"
            . "Final-Recipient: rfc822; {$recipient}\r\nOriginal-Recipient: rfc822;{$recipient}\r\nAction: failed\r\nStatus: 5.1.1\r\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 <{$recipient}>: Recipient address rejected: User unknown\r\n"
            . "\r\n--B\r\nContent-Type: message/rfc822\r\n\r\n" . $original . "\r\n--B--\r\n";
    }

    private static function encodedWord(string $text, string $charset): string
    {
        if ($charset === 'UTF-8') {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }

        return '=?' . $charset . '?B?' . base64_encode((string) mb_convert_encoding($text, $charset, 'UTF-8')) . '?=';
    }
}
