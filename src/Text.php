<?php

declare(strict_types=1);

namespace ReBuji;

/**
 * Text as people type it in Japan, where a full-width space (U+3000) is as likely as an ASCII one.
 *
 * @author shimomo
 */
final class Text
{
    /**
     * Without the spaces at either end: ASCII whitespace as trim() removes it, and Unicode spaces such as the
     * full-width space and the no-break space. Spaces inside stay.
     */
    public static function trim(string $text): string
    {
        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text) ?? trim($text);
    }
}
