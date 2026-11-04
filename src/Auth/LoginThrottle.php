<?php

declare(strict_types=1);

namespace ReBuji\Auth;

use DateTimeImmutable;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * Blocks logins for an email or an address after 5 failures within 15 minutes.
 *
 * @author shimomo
 */
final class LoginThrottle
{
    public const int MAX_FAILURES = 5;

    public const int WINDOW_MINUTES = 15;

    public function __construct(private readonly Database $db)
    {
        //
    }

    public function isBlocked(string $email, string $ip, DateTimeImmutable $now): bool
    {
        $since = Time::format($now->modify('-' . self::WINDOW_MINUTES . ' minutes'));
        $failures = 'SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND attempted_at > ? AND ';

        return (int) $this->db->fetchValue($failures . 'email = ?', [$since, AdminRepository::normalize($email)]) >= self::MAX_FAILURES
            || (int) $this->db->fetchValue($failures . 'ip = ?', [$since, $ip]) >= self::MAX_FAILURES;
    }

    public function record(string $email, string $ip, bool $succeeded, DateTimeImmutable $now): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (email, ip, succeeded, attempted_at) VALUES (?, ?, ?, ?)',
            [AdminRepository::normalize($email), $ip, $succeeded ? 1 : 0, Time::format($now)],
        );
        $this->db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [Time::format($now->modify('-1 day'))]);
    }
}
