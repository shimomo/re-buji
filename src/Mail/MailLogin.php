<?php

declare(strict_types=1);

namespace ReBuji\Mail;

/**
 * Logs in to the sending account without sending anything: how cron sees, between quakes, that mail could go out.
 *
 * @author shimomo
 */
interface MailLogin
{
    /**
     * @throws AccountUnavailable when the account cannot be used
     * @throws MailUnavailable when there are no settings
     */
    public function logIn(): void;
}
