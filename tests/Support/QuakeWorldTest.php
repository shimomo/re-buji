<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The quake, sending and reply tests run on MySQL in the CI job that has it, and on SQLite elsewhere.
 *
 * @author shimomo
 */
final class QuakeWorldTest extends TestCase
{
    #[Test]
    public function runsOnMysqlWhenTheTestsHaveIt(): void
    {
        $world = new QuakeWorld('2009-08-10T20:12:00Z');

        $this->assertSame(in_array(getenv('REBUJI_TEST_MYSQL_DSN'), [false, ''], true) ? 'sqlite' : 'mysql', $world->driver);
        $this->assertSame($world->driver, $world->db->driver());
    }
}
