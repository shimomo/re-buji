<?php

declare(strict_types=1);

namespace ReBuji\Replies;

/**
 * A mail that came back undelivered (design 5.2): which addresses failed, and which of our mails it returns. A notice
 * that delivery is only delayed is not a failure: the mail system is still trying.
 *
 * @author shimomo
 */
final readonly class Bounce
{
    /**
     * @param list<string> $recipients the failed addresses the report names (empty when it names none)
     * @param string|null $originalMessageId the Message-ID of the mail that came back, when the report carries it
     * @param bool $failed false for a notice that delivery is delayed
     */
    public function __construct(public array $recipients, public ?string $originalMessageId, public string $reason, public bool $failed = true)
    {
        //
    }
}
