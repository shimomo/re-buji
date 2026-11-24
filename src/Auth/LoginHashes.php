<?php

declare(strict_types=1);

namespace ReBuji\Auth;

/**
 * The admins' password hashes at log-in: the dummy an unknown email is compared against, and whether a hash is made
 * again. Today's default cost comes in from outside, so the choice can be checked as on an older PHP.
 *
 * @author shimomo
 */
final class LoginHashes
{
    // Compared against for unknown emails, so they take as long as a wrong password. PHP 8.3 hashes at bcrypt cost 10
    // and PHP 8.4 at cost 12, so there is one dummy per cost; the one used matches the strongest admin's hash
    // (dummy()), which stays at 12 when the server's PHP is moved back.
    private const array DUMMY_HASHES = [
        10 => '$2y$10$4Le9C6dZJZp/nbc/adhNL.C8Vc9dgAvFli32QqVHGFY4bEnlsQfzm',
        12 => '$2y$12$s8XiSL49q5/EMaaHe0/Yr.QcBRQLuedRMC.O8AwMkcGe26/4g3H86',
    ];

    private readonly int $defaultCost;

    /**
     * @param int|null $defaultCost the bcrypt cost password_hash() uses today; null reads it from PHP
     */
    public function __construct(private readonly AdminRepository $admins, ?int $defaultCost = null)
    {
        $this->defaultCost = $defaultCost ?? (defined('PASSWORD_BCRYPT_DEFAULT_COST') ? PASSWORD_BCRYPT_DEFAULT_COST : 10);
    }

    /**
     * The dummy an unknown email is compared against: the strongest admin's bcrypt hash that has a dummy, or today's
     * default when that is stronger or no admin's hash has one.
     */
    public function dummy(): string
    {
        $cost = $this->defaultCost;

        foreach ($this->admins->passwordHashes() as $hash) {
            $own = self::bcryptCost($hash);

            if ($own !== null && isset(self::DUMMY_HASHES[$own])) {
                $cost = max($cost, $own);
            }
        }

        return self::DUMMY_HASHES[$cost] ?? self::DUMMY_HASHES[10];
    }

    /**
     * Whether the hash is weaker than what password_hash() makes today: another algorithm, or bcrypt with a lower cost.
     * A stronger bcrypt hash stays, so moving the server back to an older PHP does not weaken it. This compares bcrypt
     * costs only, as PHP's default is bcrypt (LoginHashesTest::assumesBcryptIsThePhpDefault()).
     */
    public function weakerThanDefault(string $hash): bool
    {
        $cost = self::bcryptCost($hash);

        return $cost === null || $cost < $this->defaultCost;
    }

    /**
     * The cost of a bcrypt hash as password_hash() makes it ($2y$), or null for anything else.
     */
    private static function bcryptCost(string $hash): ?int
    {
        $info = password_get_info($hash);
        $cost = isset($info['options']['cost']) && is_int($info['options']['cost']) ? $info['options']['cost'] : null;

        return $info['algo'] === PASSWORD_BCRYPT ? $cost : null;
    }
}
