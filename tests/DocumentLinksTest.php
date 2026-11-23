<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The README and the docs next to it go in the release zip, and docs/superpowers/ does not: their links have to lead
 * somewhere both on GitHub and inside the zip.
 *
 * @author shimomo
 */
final class DocumentLinksTest extends TestCase
{
    #[Test]
    public function pointsEveryRelativeLinkAtAFileAndHeadingThatExist(): void
    {
        $broken = [];

        foreach (self::links() as [$file, $link]) {
            [$path, $anchor] = self::split($link);
            $target = $path === '' ? $file : self::resolve($file, $path);

            if (!is_file($target)) {
                $broken[] = self::relative($file) . ': ' . $link . '（ファイルが無い）';
            } elseif ($anchor !== null && !in_array($anchor, self::anchors($target), true)) {
                $broken[] = self::relative($file) . ': ' . $link . '（見出しが無い）';
            }
        }

        $this->assertSame([], $broken);
    }

    #[Test]
    public function keepsThePlansOutOfTheShippedDocuments(): void
    {
        // A relative link into docs/superpowers/ works on GitHub but breaks inside the zip.
        $plans = [];
        $superpowers = self::root() . '/docs/superpowers/';

        foreach (self::links() as [$file, $link]) {
            $path = self::split($link)[0];

            if ($path !== '' && str_starts_with(self::resolve($file, $path) . '/', $superpowers)) {
                $plans[] = self::relative($file) . ': ' . $link;
            }
        }

        $this->assertSame([], $plans);
    }

    #[Test]
    public function leadsEveryDocBackToTheReadme(): void
    {
        // Someone who lands on a doc from a search or a link sees where the rest is.
        $lost = [];

        foreach (self::documents() as $file) {
            if (basename($file) !== 'README.md' && !in_array('[README に戻る](../README.md)', array_slice(self::prose($file), 0, 5), true)) {
                $lost[] = self::relative($file);
            }
        }

        $this->assertSame([], $lost);
    }

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return list<string> the README and docs/*.md (not docs/superpowers/)
     */
    private static function documents(): array
    {
        $docs = glob(self::root() . '/docs/*.md');

        return [self::root() . '/README.md', ...($docs === false ? [] : $docs)];
    }

    /**
     * @return list<array{string, string}> the file and the link, for each relative link outside code blocks
     */
    private static function links(): array
    {
        $links = [];

        foreach (self::documents() as $file) {
            foreach (self::prose($file) as $line) {
                preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/u', $line, $matches);

                foreach ($matches[1] as $link) {
                    if (preg_match('/^(https?|mailto):/i', $link) !== 1) {
                        $links[] = [$file, $link];
                    }
                }
            }
        }

        return $links;
    }

    /**
     * @return list<string> the lines outside ``` code blocks
     */
    private static function prose(string $file): array
    {
        $lines = [];
        $code = false;

        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $code = !$code;
            } elseif (!$code) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The anchors GitHub gives the headings of a file: lower case, punctuation dropped, spaces turned into hyphens, and
     * -1, -2, ... after a name already taken.
     *
     * @return list<string>
     */
    private static function anchors(string $file): array
    {
        $anchors = [];
        $taken = [];

        foreach (self::prose($file) as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $heading) !== 1) {
                continue;
            }

            $name = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/u', '', mb_strtolower(trim($heading[1]))));
            $count = $taken[$name] ?? 0;
            $taken[$name] = $count + 1;
            $anchors[] = $count === 0 ? $name : $name . '-' . $count;
        }

        return $anchors;
    }

    /**
     * @return array{string, ?string} the path and the anchor (null when there is none)
     */
    private static function split(string $link): array
    {
        $hash = strpos($link, '#');

        return $hash === false ? [$link, null] : [substr($link, 0, $hash), rawurldecode(substr($link, $hash + 1))];
    }

    private static function resolve(string $file, string $path): string
    {
        $parts = [];

        foreach (explode('/', dirname($file) . '/' . rawurldecode($path)) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(self::root()) + 1);
    }
}
