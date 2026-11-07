<?php

declare(strict_types=1);

namespace ReBuji\Replies;

/**
 * The contact a mail answers, and how it was found.
 *
 * @author shimomo
 */
final readonly class ReplyMatch
{
    /**
     * @param 'thread'|'number'|'sender'|string $by
     */
    public function __construct(public int $notificationId, public string $by)
    {
        //
    }
}
