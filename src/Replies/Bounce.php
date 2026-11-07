<?php

declare(strict_types=1);

namespace ReBuji\Replies;

/**
 * A mail that came back undelivered (design 5.2): which addresses failed, and which of our mails it returns.
 *
 * @author shimomo
 */
final readonly class Bounce
{
    /**
     * @param list<string> $recipients the failed addresses the report names (empty when it names none)
     * @param string|null $originalMessageId the Message-ID of the mail that came back, when the report carries it
     */
    public function __construct(public array $recipients, public ?string $originalMessageId, public string $reason)
    {
        //
    }
}
