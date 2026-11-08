<?php

declare(strict_types=1);

namespace ReBuji\Replies;

/**
 * What the person wrote in a reply, without the quoted contact mail or their signature (design 5.2). An empty result
 * counts as safe, so only what is sure to be quoted is dropped: when in doubt a line is kept, for a person to read.
 *
 * @author shimomo
 */
final class ReplyText
{
    /**
     * Lines that start a quote without marks (a header block, a marker) or a signature: everything from them on goes.
     * Mail programs that quote this way put the answer above.
     */
    private const array CUT_FROM = [
        '/^-{2,}\s*(original message|元のメッセージ|forwarded message|転送されたメッセージ)/iu',
        // Outlook quotes the headers of the mail it answers.
        '/^(from|差出人|送信者)\s*[:：]/iu',
        '/^_{5,}\s*$/u',
        // The signature delimiter.
        '/^--\s?$/u',
        // Our own subject, quoted without marks.
        '/\[安否確認 #/u',
    ];

    /**
     * Lines that only introduce a quote marked with ">": the line goes, and words written below the quote stay.
     */
    private const array DROP = [
        '/^On .+wrote:\s*$/iu',
        // Thunderbird and others: "2026/11/05 12:12、安否確認 さんは書きました:" (the colon tells it from their words)
        '/書きました[:：]\s*$/u',
        // iPhone: "2026/11/05 12:12、安否確認 <anpi@example.co.jp>のメール:"
        '/のメール[:：]\s*$/u',
        // Gmail: "2026年11月5日(木) 12:12 安否確認 <anpi@example.co.jp>:"
        '/<[^<>\s]+@[^<>\s]+>\s*[:：]\s*$/u',
    ];

    public static function extract(string $text): string
    {
        // Bytes that are not UTF-8 would make every pattern fail; as "?" they still count as something written.
        $text = mb_scrub(str_replace(["\r\n", "\r"], "\n", $text), 'UTF-8');
        $kept = [];

        foreach (explode("\n", $text) as $line) {
            if (self::matchesAny(self::CUT_FROM, $line)) {
                break;
            }

            if (!self::matchesAny(self::DROP, $line) && preg_match('/^\s*>/u', $line) !== 1) {
                $kept[] = rtrim($line);
            }
        }

        $joined = preg_replace('/\n{3,}/u', "\n\n", implode("\n", $kept));
        $trimmed = $joined === null ? null : preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $joined);

        // A pattern that could not run must not turn words into an empty, safe reply.
        return $trimmed ?? trim($text);
    }

    /**
     * @param list<non-empty-string> $patterns
     */
    private static function matchesAny(array $patterns, string $line): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
