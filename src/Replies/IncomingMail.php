<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

/**
 * A mail read from the reply mailbox, sorted into a bounce, an auto-reply or a reply (design 5.2). Neither of the
 * first two counts as a reply: an absence notice or a returned mail says nothing about whether someone is safe.
 *
 * @author shimomo
 */
final readonly class IncomingMail
{
    public const string BOUNCE = 'bounce';

    public const string DELAYED = 'delayed';

    public const string AUTO_REPLY = 'auto_reply';

    public const string REPLY = 'reply';

    private const string AUTO_REPLY_SUBJECT = '/自動応答|自動返信|不在|out of office|automatic reply|auto[- ]?reply/iu';

    private const string DELAY_SUBJECT = '/delay|遅延|遅れ/iu';

    /**
     * @param list<string> $references Message-IDs this mail answers: In-Reply-To first, then References
     * @param list<string> $attachments the names of the files it carries
     */
    public function __construct(
        public ?string $messageId,
        public array $references,
        public string $subject,
        public string $fromAddress,
        public ?DateTimeImmutable $date,
        public string $text,
        public bool $autoReply,
        public ?Bounce $bounce,
        public array $attachments = [],
    ) {
        //
    }

    public static function parse(string $raw): self
    {
        if (trim($raw) === '') {
            throw new InvalidArgumentException('Not a mail: it is empty');
        }

        try {
            $message = Message::from($raw, false);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Not a mail: ' . $exception->getMessage(), 0, $exception);
        }

        $from = $message->getHeader(HeaderConsts::FROM);
        $fromAddress = $from instanceof AddressHeader ? (string) $from->getEmail() : '';
        $date = $message->getHeader(HeaderConsts::DATE);

        if ($fromAddress === '' && $message->getSubject() === null && $message->getMessageId() === null) {
            throw new InvalidArgumentException('Not a mail: no sender, subject or Message-ID');
        }

        $subject = mb_scrub((string) $message->getSubject(), 'UTF-8');
        $bounce = self::bounceOf($message, $fromAddress, $subject, $raw);

        return new self(
            $message->getMessageId(),
            self::references($message),
            $subject,
            $fromAddress,
            self::date($date instanceof DateHeader ? $date->getDateTime() : null),
            self::text($message),
            $bounce === null && self::isAutoReply($message, $subject),
            $bounce,
            self::attachments($message),
        );
    }

    public function kind(): string
    {
        return match (true) {
            $this->bounce !== null => $this->bounce->failed ? self::BOUNCE : self::DELAYED,
            $this->autoReply => self::AUTO_REPLY,
            default => self::REPLY,
        };
    }

    private static function date(?DateTimeInterface $date): ?DateTimeImmutable
    {
        return $date === null ? null : DateTimeImmutable::createFromInterface($date);
    }

    /**
     * @return list<string>
     */
    private static function references(IMessage $message): array
    {
        $ids = [];

        foreach ([HeaderConsts::IN_REPLY_TO, HeaderConsts::REFERENCES] as $name) {
            $value = (string) $message->getHeader($name)?->getRawValue();

            preg_match_all('/<([^<>\s]+)>/', $value, $matches);

            foreach ($matches[1] === [] && trim($value) !== '' ? [trim($value)] : $matches[1] as $id) {
                $ids[$id] = true;
            }
        }

        return array_map(strval(...), array_keys($ids));
    }

    /**
     * The text, always as UTF-8: bytes in a charset that could not be read become "?", so they still count as written.
     */
    private static function text(IMessage $message): string
    {
        $text = $message->getTextContent();

        if ($text !== null) {
            return mb_scrub($text, 'UTF-8');
        }

        $html = mb_scrub((string) $message->getHtmlContent(), 'UTF-8');

        // The quoted mail, innermost first; words written around it stay.
        do {
            $before = $html;
            $html = preg_replace('/<blockquote\b[^>]*>(?:(?!<blockquote\b).)*?<\/blockquote>/is', '', $html) ?? $before;
        } while ($html !== $before);

        $html = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li|tr)>/i'], "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private static function attachments(IMessage $message): array
    {
        $names = [];

        foreach ($message->getAllAttachmentParts() as $part) {
            $name = mb_scrub(trim((string) $part->getFilename()), 'UTF-8');
            $names[] = $name === '' ? '（名前のないファイル）' : $name;
        }

        return $names;
    }

    private static function isAutoReply(IMessage $message, string $subject): bool
    {
        $submitted = strtolower(trim((string) $message->getHeaderValue('Auto-Submitted')));
        $precedence = strtolower(trim((string) $message->getHeaderValue('Precedence')));

        return ($submitted !== '' && $submitted !== 'no')
            || $message->getHeader('X-Autoreply') !== null
            || $message->getHeader('X-Autorespond') !== null
            || in_array($precedence, ['auto_reply', 'bulk', 'junk'], true)
            || preg_match(self::AUTO_REPLY_SUBJECT, $subject) === 1;
    }

    /**
     * A delivery status report, or mail from the mail system itself. Checked before auto-replies: reports carry
     * Auto-Submitted too.
     */
    private static function bounceOf(IMessage $message, string $fromAddress, string $subject, string $raw): ?Bounce
    {
        $report = strtolower((string) $message->getContentType()) === 'multipart/report'
            && strtolower((string) $message->getHeaderParameter(HeaderConsts::CONTENT_TYPE, 'report-type')) === 'delivery-status';
        $local = strtolower(explode('@', $fromAddress)[0]);

        if (!$report && !in_array($local, ['mailer-daemon', 'postmaster'], true)) {
            return null;
        }

        $recipients = [];
        $reasons = [];
        $notFailed = false;

        foreach ($message->getAllPartsByMimeType('message/delivery-status') as $part) {
            foreach (preg_split('/\r?\n\r?\n/', (string) $part->getContent()) ?: [] as $paragraph) {
                if (preg_match('/^Final-Recipient:\s*[^;]*;\s*(\S+)/mi', $paragraph, $recipient) !== 1) {
                    continue;
                }

                $action = preg_match('/^Action:\s*(\S+)/mi', $paragraph, $found) === 1 ? strtolower($found[1]) : 'failed';

                if ($action !== 'failed') {
                    $notFailed = true;
                } else {
                    $recipients[] = trim($recipient[1], '<>');

                    foreach (['Status', 'Diagnostic-Code'] as $field) {
                        if (preg_match('/^' . $field . ':\s*(.+)$/mi', $paragraph, $line) === 1) {
                            $reasons[] = trim($line[1]);
                        }
                    }
                }
            }
        }

        // Only delays (or a plain-text warning about one) mean the mail system is still trying.
        $failed = $recipients !== [] || (!$notFailed && preg_match(self::DELAY_SUBJECT, $subject) !== 1);

        return new Bounce(array_values(array_unique($recipients)), self::originalMessageId($message, $raw), $reasons === [] ? $subject : implode(' ', $reasons), $failed);
    }

    private static function originalMessageId(IMessage $message, string $raw): ?string
    {
        $returned = '';

        foreach ([...$message->getAllPartsByMimeType('message/rfc822'), ...$message->getAllPartsByMimeType('text/rfc822-headers')] as $part) {
            $returned .= (string) $part->getContent() . "\n";
        }

        // Without a returned part, look for a Message-ID line of the original anywhere in the report.
        preg_match_all('/^Message-ID:\s*<([^<>\s]+)>/mi', $returned === '' ? $raw : $returned, $matches);

        foreach ($matches[1] as $id) {
            if ($id !== $message->getMessageId()) {
                return $id;
            }
        }

        return null;
    }
}
