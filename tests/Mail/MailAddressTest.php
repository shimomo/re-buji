<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Mail\MailAddress;
use ReBuji\Mail\RecipientRefused;

/**
 * @author shimomo
 */
final class MailAddressTest extends TestCase
{
    #[Test]
    public function keepsOrdinaryAddresses(): void
    {
        $this->assertSame('taro.yamada@docomo.ne.jp', MailAddress::forSending('taro.yamada@docomo.ne.jp')->getAddress());
    }

    #[Test]
    public function quotesAddressesWithDotsThatTheRfcRefuses(): void
    {
        // Older Japanese mobile addresses: symfony/mime refuses them as they are, but accepts the quoted local part.
        $this->assertSame('"taro..yamada"@docomo.ne.jp', MailAddress::forSending('taro..yamada@docomo.ne.jp')->getAddress());
        $this->assertSame('"taro."@docomo.ne.jp', MailAddress::forSending('taro.@docomo.ne.jp')->getAddress());
        $this->assertSame('".taro"@ezweb.ne.jp', MailAddress::forSending('.taro@ezweb.ne.jp')->getAddress());
    }

    #[Test]
    public function refusesWhatCannotBeSent(): void
    {
        $this->expectException(RecipientRefused::class);

        MailAddress::forSending('no-at-sign');
    }
}
