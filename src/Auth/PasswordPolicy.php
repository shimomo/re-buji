<?php

declare(strict_types=1);

namespace ReBuji\Auth;

/**
 * @author shimomo
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;

    /**
     * @return list<string>
     */
    public static function validate(string $password): array
    {
        return mb_strlen($password) < self::MIN_LENGTH ? ['パスワードは 12 文字以上にしてください'] : [];
    }
}
