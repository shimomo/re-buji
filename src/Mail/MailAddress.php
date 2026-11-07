<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Recipient addresses for sending.
 *
 * Older Japanese mobile addresses can have consecutive dots, or a dot first or right before "@"
 * (taro..yamada@docomo.ne.jp). symfony/mime refuses them as they are, but accepts the same mailbox with its local part
 * quoted, which is how such addresses are sent.
 *
 * @author shimomo
 */
final class MailAddress
{
    public static function forSending(string $address): Address
    {
        try {
            return new Address($address);
        } catch (RfcComplianceException $exception) {
            $at = strrpos($address, '@');

            if ($at === false || $at === 0) {
                throw new RecipientRefused('このアドレスには送れません：' . $address, 0, $exception);
            }

            $local = substr($address, 0, $at);
            $quoted = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $local) . '"' . substr($address, $at);

            try {
                return new Address($quoted);
            } catch (RfcComplianceException) {
                throw new RecipientRefused('このアドレスには送れません：' . $address, 0, $exception);
            }
        }
    }
}
