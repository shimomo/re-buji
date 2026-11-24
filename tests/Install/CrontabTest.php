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
