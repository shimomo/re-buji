<?php

declare(strict_types=1);

namespace ReBuji\Mail;

/**
 * One mail to one address.
 *
 * @author shimomo
 */
final readonly class OutgoingMail
{
    /**
     * @param string $messageId without the angle brackets; replies refer to it (design 5.2)
     */
    public function __construct(
        public string $fromAddress,
        public string $fromName,
        public string $to,
        public string $subject,
        public string $body,
        public string $messageId,
    ) {
        //
    }
}
