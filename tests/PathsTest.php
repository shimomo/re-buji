<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Paths;

/**
 * @author shimomo
 */
final class PathsTest extends TestCase
{
    #[Test]
    public function resolvesDirectoriesFromTheRoot(): void
    {
        $paths = new Paths('/srv/app');

        $this->assertSame('/srv/app', $paths->root());
        $this->assertSame('/srv/app/public', $paths->public());
        $this->assertSame('/srv/app/templates', $paths->templates());
        $this->assertSame('/srv/app/storage', $paths->storage());
        $this->assertSame('/srv/app/storage/config.php', $paths->storage('config.php'));
    }
}
