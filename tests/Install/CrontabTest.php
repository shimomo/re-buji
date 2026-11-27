<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Install\Crontab;
use RuntimeException;

/**
 * @author shimomo
 */
final class CrontabTest extends TestCase
{
    private const string CRON = '/home/you/re-buji/bin/cron.php';

    private const string LINE = '*/5 * * * * /usr/bin/php /home/you/re-buji/bin/cron.php';

    #[Test]
    public function addsALineAndKeepsTheOthers(): void
    {
        $calls = [];
        $crontab = new Crontab($this->runner($calls, [0, "MAILTO=me@example.jp\n0 3 * * * /home/you/backup.sh", ''], [0, '', '']));

        self::assertTrue($crontab->add(self::LINE, self::CRON));
        self::assertSame([['crontab', '-l'], ''], $calls[0]);
        self::assertSame([['crontab', '-'], "MAILTO=me@example.jp\n0 3 * * * /home/you/backup.sh\n" . self::LINE . "\n"], $calls[1]);
    }

    #[Test]
    public function startsACrontabWhenThereIsNone(): void
    {
        $calls = [];
        $crontab = new Crontab($this->runner($calls, [1, '', "no crontab for you\n"], [0, '', '']));

        self::assertNull($crontab->problem());
        self::assertTrue($crontab->add(self::LINE, self::CRON));
        self::assertSame(self::LINE . "\n", $calls[2][1]);
    }

    #[Test]
    public function doesNotAddTheLineTwice(): void
    {
        $calls = [];
        $crontab = new Crontab($this->runner(
            $calls,
            [0, "* * * * * /usr/local/php/8.3/bin/php /home/you/re-buji/bin/cron.php\n", ''],
            [0, '', ''],
        ));

        self::assertFalse($crontab->add(self::LINE, self::CRON));
        foreach ($calls as [$command]) {
            self::assertNotSame(['crontab', '-'], $command);
        }
    }

    #[Test]
    public function addsTheLineWhenTheOldOneIsCommentedOut(): void
    {
        $calls = [];
        $old = '  # */5 * * * * /usr/bin/php /home/you/re-buji/bin/cron.php';
        $crontab = new Crontab($this->runner($calls, [0, $old . "\n", ''], [0, '', '']));

        self::assertTrue($crontab->add(self::LINE, self::CRON));
        self::assertSame([['crontab', '-'], $old . "\n" . self::LINE . "\n"], $calls[1]);
    }

    #[Test]
    public function findsTheLineItAddedForAPathWithAPercentOrAQuote(): void
    {
        foreach (['/home/you/re 100%/bin/cron.php', "/home/o'neil/re-buji/bin/cron.php"] as $path) {
            $calls = [];
            $line = Crontab::line(5, '/usr/bin/php', $path);
            $crontab = new Crontab($this->runner($calls, [0, $line . "\n", ''], [0, '', '']));

            self::assertFalse($crontab->add($line, $path), $path);
            self::assertCount(1, $calls, $path);
        }
    }

    #[Test]
    public function addsTheLineBesideALongerPathOrAPathInAComment(): void
    {
        $others = [
            '*/5 * * * * /usr/bin/php /home/you/re-buji/bin/cron.php.bak',
            '*/5 * * * * /usr/bin/php /other/cron.php # was /home/you/re-buji/bin/cron.php',
        ];

        foreach ($others as $other) {
            $calls = [];
            $crontab = new Crontab($this->runner($calls, [0, $other . "\n", ''], [0, '', '']));

            self::assertTrue($crontab->add(self::LINE, self::CRON), $other);
            self::assertSame([['crontab', '-'], $other . "\n" . self::LINE . "\n"], $calls[1], $other);
        }
    }

    #[Test]
    public function findsTheLineFollowedByARedirect(): void
    {
        $calls = [];
        $crontab = new Crontab($this->runner($calls, [0, self::LINE . " >/dev/null 2>&1\n", ''], [0, '', '']));

        self::assertFalse($crontab->add(self::LINE, self::CRON));
        self::assertCount(1, $calls);
    }

    #[Test]
    public function saysWhyItCannotBeUsed(): void
    {
        $calls = [];
        self::assertSame(
            'crontab が見つかりません。',
            (new Crontab($this->runner($calls, [127, '', ''], [0, '', ''])))->problem(),
        );
        self::assertSame(
            'You (you) are not allowed to use this program (crontab)',
            (new Crontab($this->runner(
                $calls,
                [1, '', "You (you) are not allowed to use this program (crontab)\n"],
                [0, '', ''],
            )))->problem(),
        );
    }

    #[Test]
    public function reportsAFailedWrite(): void
    {
        $calls = [];
        $crontab = new Crontab($this->runner(
            $calls,
            [0, '', ''],
            [1, '', "\"-\":1: bad minute\nerrors in crontab file, can't install.\n"],
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('errors in crontab file');
        $crontab->add(self::LINE, self::CRON);
    }

    #[Test]
    public function saysWhyWhenPhpCannotRunCommands(): void
    {
        $calls = [];
        $crontab = new Crontab($this->runner($calls, [0, '', ''], [0, '', '']), false);
        $reason = 'この PHP では proc_open が止められているため、crontab を動かせません。';

        self::assertSame($reason, $crontab->problem());

        try {
            $crontab->add(self::LINE, self::CRON);
            self::fail('add() did not throw');
        } catch (RuntimeException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }

        self::assertSame([], $calls);
    }

    #[Test]
    public function quotesASingleQuoteWithoutEscapeshellarg(): void
    {
        self::assertSame(
            "*/5 * * * * /usr/bin/php '/home/o'\\''neil/re-buji/bin/cron.php'",
            Crontab::line(5, '/usr/bin/php', "/home/o'neil/re-buji/bin/cron.php"),
        );
    }

    #[Test]
    public function buildsTheLine(): void
    {
        self::assertSame(
            '* * * * * /usr/bin/php /home/you/re-buji/bin/cron.php',
            Crontab::line(1, '/usr/bin/php', self::CRON),
        );
        self::assertSame(
            "*/5 * * * * '/opt/my php/bin/php' '/home/you/re 100\\%/bin/cron.php'",
            Crontab::line(5, '/opt/my php/bin/php', '/home/you/re 100%/bin/cron.php'),
        );
    }

    #[Test]
    public function takesALongErrorOutputWithoutWaiting(): void
    {
        // The child writes 200,000 bytes of errors without blocking and gives up after 3 seconds if nobody reads them.
        $code = <<<'PHP'
            stream_set_blocking(STDERR, false);
            $left = str_repeat('e', 200000);
            $until = microtime(true) + 3;
            while ($left !== '' && microtime(true) < $until) {
                $written = (int) fwrite(STDERR, $left);
                $left = substr($left, $written);
                if ($written === 0) {
                    usleep(10000);
                }
            }
            echo $left === '' ? 'all' : 'stuck';
            PHP;

        [$status, $output, $error] = Crontab::runCommand([PHP_BINARY, '-n', '-r', $code], '');

        self::assertSame([0, 'all', 200000], [$status, $output, strlen($error)]);
    }

    #[Test]
    public function stopsACommandThatDoesNotTakeTheWholeInput(): void
    {
        // Like a crontab that would install what it has read: it stops reading, and would go on to install a second later.
        $code = 'fread(STDIN, 10); fclose(STDIN); sleep(1); echo "installed";';

        [$status, $output, $error] = Crontab::runCommand([PHP_BINARY, '-n', '-r', $code], str_repeat("x\n", 500000));

        self::assertSame([1, '', 'crontab に新しい中身を最後まで渡せなかったので、途中で止めました。'], [$status, $output, $error]);
    }

    /**
     * @param list<array{list<string>, string}> $calls
     * @param array{int, string, string} $read
     * @param array{int, string, string} $write
     * @return Closure(list<string>, string): array{int, string, string}
     */
    private function runner(array &$calls, array $read, array $write): Closure
    {
        return static function (array $command, string $input) use (&$calls, $read, $write): array {
            $calls[] = [$command, $input];

            return $command === ['crontab', '-l'] ? $read : $write;
        };
    }
}
