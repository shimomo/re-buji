<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Install\InstallKey;
use ReBuji\Paths;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class InstallKeyTest extends TestCase
{
    #[Test]
    public function createsTheKeyOnceAndComparesSafely(): void
    {
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/storage');
            $key = new InstallKey(new Paths($root));
            $key->ensure();
            $first = (string) file_get_contents($root . '/storage/install-key.txt');
            $key->ensure();
            clearstatcache();
            $permissions = fileperms($root . '/storage/install-key.txt');

            $this->assertSame($first, (string) file_get_contents($root . '/storage/install-key.txt'));
            $this->assertSame(24, strlen(trim($first)));
            $this->assertNotFalse($permissions);
            $this->assertSame(0600, $permissions & 0777);
            $this->assertTrue($key->matches(trim($first)));
            $this->assertTrue($key->matches(' ' . trim($first) . "\n"));
            $this->assertFalse($key->matches('x'));
            $this->assertFalse($key->matches(''));

            $key->forget();
            $this->assertFileDoesNotExist($root . '/storage/install-key.txt');
            $this->assertFalse($key->matches(trim($first)));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
