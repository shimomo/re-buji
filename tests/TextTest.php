<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Text;

/**
 * @author shimomo
 */
final class TextTest extends TestCase
{
    #[Test]
    public function trimsFullWidthSpacesToo(): void
    {
        // The full-width space inside the name stays.
        $this->assertSame('山田　花子', Text::trim("　山田　花子 \u{00A0}"));
        $this->assertSame('', Text::trim('　　'));
        $this->assertSame('', Text::trim(" \t\n"));
        $this->assertSame('山田', Text::trim('山田'));
    }
}
