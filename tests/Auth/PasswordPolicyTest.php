<?php

declare(strict_types=1);

namespace ReBuji\Tests\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Auth\PasswordPolicy;

/**
 * @author shimomo
 */
final class PasswordPolicyTest extends TestCase
{
    #[Test]
    public function requiresTwelveCharacters(): void
    {
        $this->assertSame(['パスワードは 12 文字以上にしてください'], PasswordPolicy::validate('short'));
        $this->assertSame([], PasswordPolicy::validate(str_repeat('a', 12)));
        $this->assertSame(['パスワードは 12 文字以上にしてください'], PasswordPolicy::validate('あいうえおかきくけこさ'));
        $this->assertSame([], PasswordPolicy::validate('あいうえおかきくけこさし'));
    }
}
