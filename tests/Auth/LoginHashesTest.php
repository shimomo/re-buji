<?php

declare(strict_types=1);

namespace ReBuji\Tests\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Auth\AdminRepository;
use ReBuji\Auth\LoginHashes;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Tests\Support\FrozenClock;

/**
 * @author shimomo
 */
final class LoginHashesTest extends TestCase
{
    private const string COST_10 = '$2y$10$4Le9C6dZJZp/nbc/adhNL.C8Vc9dgAvFli32QqVHGFY4bEnlsQfzm';

    private const string COST_12 = '$2y$12$s8XiSL49q5/EMaaHe0/Yr.QcBRQLuedRMC.O8AwMkcGe26/4g3H86';

    #[Test]
    public function comparesAnUnknownEmailWithTheStrongestAdminsCost(): void
    {
        // A server moved back from PHP 8.4 (cost 12) to 8.3 (cost 10): the admins' hashes stay at 12.
        $admins = self::admins([self::COST_10, self::COST_12]);

        $this->assertStringStartsWith('$2y$12$', (new LoginHashes($admins, 10))->dummy());
    }

    #[Test]
    public function fallsBackToTheDefaultCost(): void
    {
        $this->assertStringStartsWith('$2y$10$', (new LoginHashes(self::admins([]), 10))->dummy());
        // No dummy for cost 13.
        $this->assertStringStartsWith('$2y$12$', (new LoginHashes(self::admins([password_hash('x', PASSWORD_BCRYPT, ['cost' => 13])]), 12))->dummy());
        // An admin not rehashed since the upgrade: today's default is the stronger.
        $this->assertStringStartsWith('$2y$12$', (new LoginHashes(self::admins([self::COST_10]), 12))->dummy());
        // Hashes that are not bcrypt as password_hash() makes it are not counted ($2a$ read as cost 12 would give 12).
        $this->assertStringStartsWith('$2y$10$', (new LoginHashes(self::admins(['not a hash', '$2a$12$' . substr(self::COST_12, 7)]), 10))->dummy());
    }

    #[Test]
    public function usesPhpsDefaultCostWhenNoneIsGiven(): void
    {
        // As the app builds it. On a PHP whose default cost has no dummy, this fails.
        $hashes = new LoginHashes(self::admins([]));

        $this->assertStringStartsWith('$2y$' . sprintf('%02d', PASSWORD_BCRYPT_DEFAULT_COST) . '$', $hashes->dummy());
        $this->assertTrue($hashes->weakerThanDefault(password_hash('x', PASSWORD_BCRYPT, ['cost' => PASSWORD_BCRYPT_DEFAULT_COST - 1])));
    }

    #[Test]
    public function rehashesOnlyAWeakerHash(): void
    {
        $hashes = new LoginHashes(self::admins([]), 12);

        $this->assertTrue($hashes->weakerThanDefault(self::COST_10));
        $this->assertFalse($hashes->weakerThanDefault(self::COST_12));
        $this->assertFalse($hashes->weakerThanDefault(password_hash('x', PASSWORD_BCRYPT, ['cost' => 13])));
        $this->assertTrue($hashes->weakerThanDefault('$2a$12$' . substr(self::COST_12, 7)));
        $this->assertTrue($hashes->weakerThanDefault('not a hash'));
    }

    #[Test]
    public function assumesBcryptIsThePhpDefault(): void
    {
        $this->assertSame(PASSWORD_BCRYPT, PASSWORD_DEFAULT, 'LoginHashes compares bcrypt costs and keeps bcrypt dummies: revisit weakerThanDefault() and dummy() if PHP\'s default algorithm changes');
    }

    /**
     * Admins with these password hashes.
     *
     * @param list<string> $hashes
     */
    private static function admins(array $hashes): AdminRepository
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $admins = new AdminRepository($db, $clock);

        foreach ($hashes as $i => $hash) {
            $id = $admins->create('管理 ' . $i, 'admin' . $i . '@example.jp', 'long-enough-pass');
            $db->execute('UPDATE admins SET password_hash = ? WHERE id = ?', [$hash, $id]);
        }

        return $admins;
    }
}
