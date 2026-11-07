<?php

declare(strict_types=1);

namespace ReBuji\Replies;

/**
 * What the person wrote in a reply, without the quoted contact mail or their signature (design 5.2). Mail programs
 * quote in different ways; each of these lines starts the quoted part, and everything from it on is dropped.
 *
 * @author shimomo
 */
final class ReplyText
{
    private const array QUOTE_STARTS = [
        '/^-{2,}\s*(original message|元のメッセージ|forwarded message|転送されたメッセージ)/iu',
        '/^On .+wrote:\s*$/i',
        '/書きました[:：]?\s*$/u',
        // iPhone: "2026/11/05 12:12、安否確認 <anpi@example.co.jp>のメール:"
        '/のメール[:：]\s*$/u',
        // Gmail: "2026年11月5日(木) 12:12 安否確認 <anpi@example.co.jp>:"
        '/<[^<>\s]+@[^<>\s]+>\s*[:：]\s*$/u',
        // Outlook quotes the headers of the mail it answers.
        '/^(from|差出人|送信者)\s*[:：]/iu',
        '/^_{5,}\s*$/',
        // The signature delimiter.
        '/^--\s?$/',
        // Our own subject, quoted without marks.
        '/\[安否確認 #/u',
    ];

    public static function extract(string $text): string
    {
        $kept = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            foreach (self::QUOTE_STARTS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    break 2;
                }
            }

            if (preg_match('/^\s*>/u', $line) !== 1) {
                $kept[] = rtrim($line);
            }
        }

        $joined = (string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $kept));

        return (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $joined);
    }
}
