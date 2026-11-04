<?php

declare(strict_types=1);

namespace ReBuji\Auth;

use DateTimeImmutable;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * Holds off logins after repeated failures (design 3.2): 5 failures in a row for one email from one address within
 * 15 minutes, or 20 failures from one address across any emails. Keyed on the pair, so failures sent from elsewhere
 * cannot lock an admin out. LoginController does not record attempts made while blocked, so a block ends 15 minutes
 * after the last failure that counted.
 *
 * @author shimomo
 */
final class LoginThrottle
{
    public const int MAX_FAILURES = 5;

    public const int MAX_FAILURES_PER_ADDRESS = 20;

    public const int WINDOW_MINUTES = 15;

    public function __construct(private readonly Database $db)
    {
        //
    }

    public function isBlocked(string $email, string $ip, DateTimeImmutable $now): bool
    {
        $since = Time::format($now->modify('-' . self::WINDOW_MINUTES . ' minutes'));
        $email = AdminRepository::normalize($email);
        $failures = 'SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND attempted_at > ? AND ip = ?';
        // "In a row": failures after this pair's last success.
        $lastSuccess = (int) $this->db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM login_attempts WHERE succeeded = 1 AND email = ? AND ip = ?', [$email, $ip]);

        return (int) $this->db->fetchValue($failures . ' AND email = ? AND id > ?', [$since, $ip, $email, $lastSuccess]) >= self::MAX_FAILURES
            || (int) $this->db->fetchValue($failures, [$since, $ip]) >= self::MAX_FAILURES_PER_ADDRESS;
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
